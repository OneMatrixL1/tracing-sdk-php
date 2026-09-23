<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\Hash\Keccak256Hasher;
use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;
use Tracing\Sdk\Verify\AnchoredEventDecoder;
use Tracing\Sdk\Verify\MerkleProof;

/**
 * Cases come from testdata/merkle.json (shared with every other language
 * SDK), whose roots were computed by merkletreejs, independently of this code.
 */
class MerkleProofTest extends TestCase
{
    private const TX_HASH = '0x9f42bb1c5b1e2b7f2c1d8e3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e';

    /** @var FakeRpcTransport */
    private $rpc;

    /** @var TracingSDK */
    private $sdk;

    protected function setUp(): void
    {
        $this->rpc = new FakeRpcTransport();
        $this->sdk = new TracingSDK([
            'endpoint' => 'https://indexer.example.com',
            'options' => new SendOptions('json', null, 'https://rpc.example.com'),
            'auth' => ['type' => 'apiToken', 'token' => 'test-token'],
        ]);
        $this->sdk->setTransportForTesting(new FakeTransport());
        $this->sdk->setRpcTransportForTesting($this->rpc);
    }

    public static function caseProvider(): array
    {
        $cases = [];

        foreach (FixtureLoader::load('merkle.json')['cases'] as $case) {
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    private static function case(string $id): array
    {
        return self::caseProvider()[$id][0];
    }

    /** A receipt whose one Anchored event carries $anchored. */
    private static function receiptAnchoring(string $anchored): array
    {
        $topic = (new AnchoredEventDecoder(new Keccak256Hasher()))->topic();

        return [
            'logs' => [[
                'topics' => [$topic, $anchored],
                'data' => '0x' . str_pad(dechex(1700000000), 64, '0', STR_PAD_LEFT),
            ]],
        ];
    }

    /**
     * @dataProvider caseProvider
     */
    public function testComputeRootMatchesTheFixture(array $case): void
    {
        $root = MerkleProof::computeRoot($case['leaf'], $case['proof']);

        $this->assertSame($case['valid'], $root === $case['root']);
    }

    /**
     * @dataProvider caseProvider
     */
    public function testVerifyAgainstATransactionAnchoringTheRoot(array $case): void
    {
        $this->rpc->receipt = self::receiptAnchoring($case['root']);

        $proof = array_merge([self::TX_HASH], $case['proof']);
        $verified = $this->sdk->verify($case['leaf'], $proof, TracingSDK::MODE_MERKLE_PROOF);

        $this->assertSame($case['valid'], $verified);
        // The first proof element is the transaction, and only it is fetched.
        $this->assertSame(
            [['rpcUrl' => 'https://rpc.example.com', 'txHash' => self::TX_HASH, 'timeoutMs' => null]],
            $this->rpc->calls
        );
    }

    public function testPairHashingIgnoresTheOrderOfTheTwoNodes(): void
    {
        $a = self::TX_HASH;
        $b = self::case('two-leaf-tree-left')['root'];

        $this->assertSame(MerkleProof::hashPair($a, $b), MerkleProof::hashPair($b, $a));
    }

    public function testComputeRootRejectsASiblingThatIsNot32Bytes(): void
    {
        $this->expectException(ConfigException::class);

        MerkleProof::computeRoot(self::TX_HASH, ['0xabcd']);
    }

    public function testAcceptsUppercaseHex(): void
    {
        $case = self::case('five-leaf-tree-leaf-0');
        $this->rpc->receipt = self::receiptAnchoring($case['root']);

        $proof = array_map('strtoupper', array_merge([self::TX_HASH], $case['proof']));

        $this->assertTrue($this->sdk->verify(strtoupper($case['leaf']), $proof, TracingSDK::MODE_MERKLE_PROOF));
    }

    public function testVerifiesWhatQueryByHashReturns(): void
    {
        $case = self::case('sixteen-leaf-tree-leaf-7');
        $transport = new FakeTransport();
        $transport->queryResponse = [
            'statusCode' => 200,
            'body' => [
                'hash' => $case['leaf'],
                'proof' => array_merge([self::TX_HASH], $case['proof']),
                'proofType' => 'merkleProof',
            ],
        ];
        $this->sdk->setTransportForTesting($transport);
        $this->rpc->receipt = self::receiptAnchoring($case['root']);

        $anchor = $this->sdk->queryByHash($case['leaf']);

        $this->assertSame(TracingSDK::MODE_MERKLE_PROOF, $anchor['proofType']);
        $this->assertTrue($this->sdk->verify($anchor['hash'], $anchor['proof'], $anchor['proofType']));
    }

    public function testReturnsFalseWhenTheTransactionAnchorsAnotherRoot(): void
    {
        $case = self::case('five-leaf-tree-leaf-0');
        $this->rpc->receipt = self::receiptAnchoring(self::case('sixteen-leaf-tree-leaf-0')['root']);

        $proof = array_merge([self::TX_HASH], $case['proof']);

        $this->assertFalse($this->sdk->verify($case['leaf'], $proof, TracingSDK::MODE_MERKLE_PROOF));
    }

    public function testASingleProofElementIsAOneLeafTree(): void
    {
        $case = self::case('single-leaf-tree');
        $this->rpc->receipt = self::receiptAnchoring($case['root']);

        $this->assertTrue($this->sdk->verify($case['leaf'], self::TX_HASH, TracingSDK::MODE_MERKLE_PROOF));
    }

    public function testRejectsAnEmptyProof(): void
    {
        $this->expectException(ConfigException::class);

        $this->sdk->verify(self::TX_HASH, [], TracingSDK::MODE_MERKLE_PROOF);
    }

    public function testRejectsASiblingThatIsNot32BytesNamingIt(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('proof[1]');

        $this->sdk->verify(self::TX_HASH, [self::TX_HASH, '0xabcd'], TracingSDK::MODE_MERKLE_PROOF);
    }

    public function testRejectsAProofThatIsNeitherAStringNorAnArray(): void
    {
        $this->expectException(ConfigException::class);

        $this->sdk->verify(self::TX_HASH, 42, TracingSDK::MODE_MERKLE_PROOF);
    }

    public function testThrowsWhenTheNodeDoesNotKnowTheTransaction(): void
    {
        $this->rpc->receipt = null;

        $this->expectException(TransportException::class);

        $this->sdk->verify(self::TX_HASH, [self::TX_HASH], TracingSDK::MODE_MERKLE_PROOF);
    }

    public function testTransactionHashModeChecksEachListedTransactionUntilOneMatches(): void
    {
        $leaf = self::case('single-leaf-tree')['leaf'];
        $this->rpc->receipt = self::receiptAnchoring($leaf);

        $transactions = [self::TX_HASH, '0x' . str_repeat('ee', 32)];

        $this->assertTrue($this->sdk->verify($leaf, $transactions, TracingSDK::MODE_TRANSACTION_HASH));
        // The first transaction already matched, so the second is never fetched.
        $this->assertCount(1, $this->rpc->calls);
    }
}
