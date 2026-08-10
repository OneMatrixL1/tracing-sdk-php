<?php

declare(strict_types=1);

namespace Tracing\Sdk\Canonicalize;

use Tracing\Sdk\Exception\CanonicalizationException;

/**
 * Canonical XML via the W3C C14N algorithm (DOMDocument::C14N()): it fixes
 * attribute/namespace ordering and insignificant whitespace so two
 * differently-serialized-but-equivalent documents hash identically.
 *
 * External entity resolution is disabled throughout — rawData is untrusted
 * caller input and must never trigger XXE (file disclosure / SSRF).
 */
class XmlCanonicalizer implements CanonicalizerInterface
{
    public function canonicalize(string $rawData): string
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        $previousDisableEntities = null;
        if (PHP_VERSION_ID < 80000) {
            // Deprecated (and a no-op, entities are disabled by default) since PHP 8.0.
            $previousDisableEntities = libxml_disable_entity_loader(true);
        }

        try {
            $dom = new \DOMDocument();
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;
            $dom->preserveWhiteSpace = false;

            $loaded = $dom->loadXML($rawData, LIBXML_NONET);

            if (!$loaded) {
                throw new CanonicalizationException('Invalid XML payload: ' . $this->formatLibxmlErrors());
            }

            $canonical = $dom->C14N();

            if ($canonical === false) {
                throw new CanonicalizationException('Failed to canonicalize XML payload');
            }

            return $canonical;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
            if ($previousDisableEntities !== null) {
                libxml_disable_entity_loader($previousDisableEntities);
            }
        }
    }

    private function formatLibxmlErrors(): string
    {
        $messages = array_map(
            static function (\LibXMLError $error): string {
                return trim($error->message);
            },
            libxml_get_errors()
        );

        return implode('; ', $messages) ?: 'unknown error';
    }
}
