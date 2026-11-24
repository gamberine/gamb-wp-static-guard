# Análises, destino e manual

O painel separa configuração de evidências. O grupo **Análises e auditoria** reúne resumo de pendências, auditoria da publicação, auditoria da origem, diagnóstico da instalação, dependências e inventário.

- **Publicação:** o relatório identifica se o HTML foi inspecionado no preparo da exportação ou na pasta entregue. Registra controles, documentos, etapa, resultado e providências. A inspeção para no primeiro impedimento crítico; não aprova documentos restantes. Uma pasta sem HTML não é aprovada.
- **Origem:** Home e uma página inexistente. A opção de relatório é independente da publicação; não substitui sua evidência. Relatórios antigos continuam compatíveis, mas devem ser regenerados para registrar cobertura detalhada.
- **Arquivos:** o manifesto da entrega local fornece caminhos relativos, tamanho, existência e links de recursos públicos permitidos. Paginação de 100 arquivos; JSON completo para download. Unidades de processamento e quantidade de arquivos físicos são métricas diferentes. A lista não certifica arquivos alheios ao manifesto nem o conteúdo de um ZIP.
- **Dependências:** distingue recursos externos de links de navegação. A lista de navegação tem limite de 200 endereços. Credenciais e parâmetros das referências são omitidos. HTML/CSS inspecionados não cobrem requisições criadas por JavaScript.

## Limites e acesso

As rotas `insights`, `destination` e `publication-audit` exigem `manage_options`, como os demais controles administrativos. Operações de escrita usam a reserva e o bloqueio existentes. Os downloads exigem administrador autenticado e nonce específico por tipo; não aceitam caminho de arquivo como entrada e usam `private, no-store` e `nosniff`. Os relatórios podem conter caminhos e estrutura da publicação: não são documentos públicos.

A verificação e a criação de pasta compartilham a validação da entrega local. Rejeitam raiz do disco, raiz/ancestrais do WordPress, áreas internas, travessia, nomes reservados, fluxos alternativos, caminhos de rede, links/junções e pastas que contêm marcadores de aplicação. A verificação não cria nada. A criação depende de um clique explícito, revalida o caminho e não salva configurações nem modifica DNS, TLS ou hosts virtuais. Salvar um destino local exige pasta existente e gravável.

## Manual público

Fonte empacotada: `assets/docs/manual.html`. Rota pública na origem: `/ajuda/publicacao-estatica/`, relativa à URL do site. Uma conversão completa também entrega `ajuda/publicacao-estatica/index.html`. O documento é independente, sem chamadas a APIs, inventários, caminhos reais ou credenciais. A rota WordPress envia CSP com hashes dos scripts incorporados, `nosniff`, `noindex, nofollow` e política sem referência de origem. A publicação estática depende das regras de headers aceitas pelo host.

O manual detalhado anterior foi preservado em `docs/manual-detalhado.html`; não integra o pacote de execução. A versão simplificada pública acompanha a navegação atual e explica limites de cada função.

## Validação

Execute `tests/run.php` com `GAMB_WP_HTML_API` apontando para uma instalação WordPress disponível e `node tests/admin-ui.cjs`. As regressões cobrem criação explícita de pasta, rejeição de caminhos, separação dos relatórios, controles aprovados/reprovados, inventário com travessia inválida e ausência de dados operacionais no manual. A validação visual deve conferir os 18 painéis, preservação dos 35 campos existentes, ícones de ajuda, rolagem interna, paginação e atalhos fixos em desktop e celular.

Headers HTTP, MIME, integridade após CDN/proxy, execução de JavaScript e o endereço efetivamente servido exigem validação no host de publicação. Uma auditoria de HTML aprovada não substitui esses testes.
