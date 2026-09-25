# Security Policy

## Reporting

Do not open a public issue for a security vulnerability.

Report suspected vulnerabilities privately to the repository owner through GitHub. Include the affected component, reproduction steps, and impact where known.

## Secrets

Never commit:

- `config.php` with real database credentials
- provider API keys
- payment-provider secrets
- admin passwords
- private keys
- production backups or database dumps

The repository `.gitignore` intentionally excludes local configuration and secret files. Keep production credentials outside version control.

## Supported code

Security fixes target the current `main` branch.
