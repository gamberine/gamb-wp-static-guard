# Governança de versões e pacotes

## Convenção

Adota-se `MAJOR.MINOR.PATCH`. Uma versão principal registra alterações incompatíveis em identificadores públicos ou integrações. Uma versão secundária acrescenta funcionalidade compatível. Uma correção de manutenção altera comportamento sem modificar o contrato público esperado.

Cada publicação deve conter código identificado, notas de versão, etiqueta Git, pacote instalável e `SHA256SUMS`. Etiquetas e arquivos publicados não devem ser reutilizados para conteúdo diferente.

## Separação de responsabilidades

O repositório contém somente o plugin, sua documentação, testes e automação de distribuição. Temas, bancos, uploads, configurações locais, artefatos de estações e credenciais permanecem fora dele.

A adaptação de um consumidor pode ser registrada em seu relatório de implantação, mas não deve ser incorporada ao núcleo como regra específica de um portal.

## Publicação

1. Atualize a versão no cabeçalho, na constante principal, no gerenciador de opções e nos metadados de distribuição.
2. Execute testes e valide o pacote em homologação.
3. Registre a alteração no changelog e gere o pacote com `python scripts/build.py`.
4. Crie uma etiqueta anotada `vX.Y.Z` no commit validado.
5. Publique as notas, o ZIP e `SHA256SUMS` na versão correspondente do GitHub.
6. Confira o commit remoto, o resultado da integração contínua e os hashes dos arquivos enviados.

As etiquetas históricas importadas identificam a instalação preservada e a incorporação das correções anteriores. Sua data de importação não deve ser apresentada como uma data de desenvolvimento retroativa.
