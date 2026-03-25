# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-03-23

### Added
- `Client` — Main SDK entry point with resource-based API
- `verification()->init()` — Create init tokens for verification sessions
- `verification()->getResult()` — Retrieve verification session results
- `tokens()->verify()` — Verify Xident verification tokens (cheap path)
- `webhooks()->verifySignature()` — HMAC-SHA256 webhook signature verification
- `webhooks()->constructEvent()` — Verify + parse webhook events
- Typed response objects: `InitResult`, `SessionResult`, `TokenResult`
- Exception hierarchy: Authentication, Validation, NotFound, RateLimit, Server, Network
- Automatic retry with exponential backoff on 5xx errors
- TLS 1.2+ enforcement
- Zero external dependencies (native cURL)
- PHP 8.1+ with strict types, readonly classes, enums
- 96 unit tests with 100% code coverage
- Framework examples: Laravel, Symfony, WordPress
- Manual autoloader for non-Composer environments
