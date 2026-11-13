# Procedimento de contribuição

Cada alteração deve identificar o problema, o comportamento resultante, o impacto de compatibilidade e a evidência de validação. Modificações específicas de temas consumidores devem permanecer em seus próprios projetos.

1. Crie uma branch a partir de `main`.
2. Restrinja a alteração ao escopo documentado.
3. Execute os testes independentes e a análise sintática dos arquivos PHP.
4. Valide integração com WordPress quando houver alteração de hooks, persistência, API ou saída pública.
5. Atualize o registro de versões e a documentação pertinente.
6. Apresente o pull request com evidências e procedimento de recuperação.

Não inclua credenciais, bancos, uploads, dados pessoais, caminhos internos de estações ou cópias de aplicações consumidoras. Não utilize dados editoriais reais em testes automatizados.

Pacotes publicados não devem ser sobrescritos. Uma correção posterior exige nova versão, etiqueta e resumo SHA-256. Consulte `docs/RELEASES.md`.
