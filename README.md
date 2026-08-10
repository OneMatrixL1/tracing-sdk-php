# Tracing SDK

Client SDK for submitting data-integrity proofs to a Tracing Indexer service. It takes a raw record (XML or JSON), canonicalizes it, hashes it with Keccak-256, and sends the resulting proof to an Indexer over HTTP — so the original data's integrity can be verified later. See [`tracing-sdk.md`](tracing-sdk.md) for the full design spec.

## Shared test vectors

[`testdata/`](testdata/) holds language-agnostic fixtures for Keccak-256 hashing and JSON/XML canonicalization. Every language SDK's test suite loads these instead of hardcoding expected outputs, so all implementations stay compatible with each other.

## Language SDKs

| Language | SDK |
| --- | --- |
| PHP | [`php/README.md`](php/README.md) |

## Indexer service

The Indexer is a separate service, external to the SDK, that receives data from the SDK and handles storage and later lookup of the integrity proofs.
