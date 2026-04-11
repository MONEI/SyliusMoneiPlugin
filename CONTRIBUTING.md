# Contributing to SyliusMoneiPlugin

Thank you for considering contributing to the MONEI Sylius plugin.

## How to contribute

1. **Fork** this repository
2. **Create a feature branch** from `main` (`git checkout -b feature/my-feature`)
3. **Write tests** for your changes — we aim for >80% coverage
4. **Run the full check suite** before committing:
   ```bash
   make check
   ```
5. **Commit** with clear, conventional messages
6. **Push** to your fork and open a **Pull Request**

## Development setup

```bash
git clone git@github.com:MONEI/SyliusMoneiPlugin.git
cd SyliusMoneiPlugin
make install
make check
```

## Code standards

- **PHPStan level 7** — `make analyse`
- **PHP CS Fixer (PSR-12 + Symfony)** — `make fix` to auto-fix, `make check` to verify
- **PHPUnit** — `make test`

## Reporting issues

Use [GitHub Issues](https://github.com/MONEI/SyliusMoneiPlugin/issues). Please include:

- Sylius version
- PHP version
- Steps to reproduce
- Expected vs actual behaviour

## Code of Conduct

Be respectful. We follow the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/).
