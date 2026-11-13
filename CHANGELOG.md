# Registro de versões

Este documento distingue a incorporação histórica das novas alterações. As datas correspondem ao registro verificável, sem reconstruir datas de commits inexistentes.

## 4.1.1 - 2026-11-13

- Preservar avisos de licença e direitos autorais nas bibliotecas CSS e JavaScript publicadas.
- Manter a remoção de comentários internos e de referências de mapa de código.
- Acrescentar regressões para preservação de avisos legais.

## 4.1.0 - 2026-11-13

- Barra lateral com duas caixas recolhíveis separadas: STATIC/Conversão - HTML/ZIP e GUARD/Segurança - Blindado.
- Caixa de escopo independente, seletor com seta, nomenclatura solicitada, explicações e comando Converter & Publicar Agora.
- Primeira publicação obrigatoriamente completa, validada também no servidor; liberação dos demais escopos após entrega bem-sucedida.
- Gerenciador de backups com listagem, download, exclusão autorizada e retenção das seis cópias mais recentes.
- Exclusão restrita aos arquivos reconhecidos dentro da pasta de backup, sem seguir links simbólicos.
- Regressões adicionais para escopo inicial e retenção.

## 4.0.0 - 2026-11-13

### Alterações de compatibilidade

- Namespace `GambWPStaticGuard`, API `gamb-wp-static-guard/v1`, página administrativa, configuração e domínio de tradução próprios.
- Migração única da configuração anterior, com preservação da origem e dos valores existentes. Integrações PHP e clientes de API precisam adotar os identificadores novos.
- Índice de pesquisa renomeado para `search-index.json`.
- Remoção da desativação automática de extensões de terceiros e da geração automática de SQL na ativação.

### Segurança e operação

- Lista permitida de recursos públicos aplicada à resolução de tokens e à materialização de arquivos. Código de servidor, dumps, arquivos ocultos e diretórios de backup são rejeitados.
- Validação final de HTML, remoção de regras especulativas e comentários de origem, com interrupção da entrega em referências internas residuais.
- Painel com validação de entrada, autorização administrativa e renderização de mensagens por APIs DOM.
- Downloads administrativos sem saída anterior ao arquivo binário e com validação de diretório e nome.
- Perfil preservado nas atualizações; módulo auxiliar condicionado à ativação do plugin principal.
- Controles de estilos e classes optativos para não pressupor a arquitetura do tema consumidor.
- Documentação, testes independentes, integração contínua e geração de pacotes com SHA-256.

## 3.0.1 - incorporação registrada em 2026-11-13

Sete arquivos diferem da base preservada 3.0.0. O levantamento está em `docs/INCORPORATION.json`.

- Correção de expressões regulares que comprometiam HTML e JavaScript.
- Correção de saída binária contaminada por bytes anteriores.
- Reescrita de caminhos em subpastas e permalinks com controlador PHP.
- Inclusão de tokens já presentes nas respostas da origem e de dependências CSS.
- Descoberta de paginação, geração de erro 404 e pesquisa local.
- Validação de destino, concorrência, gravação e entrega, sem retorno indevido de sucesso.
- Manifesto de arquivos gerados e empacotamento estático com alternativa à extensão ZIP.

## 3.0.0 - base preservada

Importação da instalação anterior, cuja identificação interna informava 12/11/2026. O registro não implica validação de todas as funcionalidades então anunciadas. Esta versão é mantida exclusivamente para rastreabilidade.
