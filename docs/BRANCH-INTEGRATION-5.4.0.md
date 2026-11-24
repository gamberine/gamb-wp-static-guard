# Consolidação das branches na 5.4.0

O repositório utiliza histórico linear na `main`, com integrações anteriores por squash. Algumas branches locais antigas continuavam aparecendo como divergentes mesmo sem alterações de conteúdo pendentes.

Antes da consolidação, foram verificadas as árvores completas:

- `feature/security-audit-github-updater` (`2d1025d`) tem árvore idêntica à release 5.0.0 (`4cdac62`). As branches históricas de portabilidade e correções anteriores pertencem a essa mesma linha já publicada.
- `fix/static-export-and-pause` (`860a8d9`) tem árvore idêntica à release 5.3.0 (`488a971`). Seu código já havia sido integrado pelo PR #8.
- `feat/version-monitor` e `release/5.2.0` já são ancestrais da `main` 5.3.0.

A branch `release/5.4.0` reúne esses históricos, preservando integralmente o código validado mais recente. Nenhuma implementação histórica substitui a versão atual. A integração final na `main` respeita a proteção de histórico linear e as verificações obrigatórias; não há force-push, remoção de branches ou alteração de worktrees de outros trabalhos.
