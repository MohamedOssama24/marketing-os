# Decision log

- 2026-10-28: MVP target segment is WordPress/WooCommerce businesses; architecture remains industry-flexible.
- 2026-10-28: First release is an internal/closed-beta tool supporting human-reviewed AI, not automatic publication.
- 2026-10-28: Local stack uses Docker Compose, Nginx, PHP-FPM 8.3, MySQL 8.4.
- 2026-10-28: Use a simple PHP/PDO starter to keep the first setup transparent and low-cost. Before production, evaluate moving to Laravel for ecosystem support and maintainability.
- 2026-10-28: Tenant organization ID is derived from authenticated user on the server, never trusted from request payload.
