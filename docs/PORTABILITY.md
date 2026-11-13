# Aproveitamento das correções em novas instalações

Este documento classifica as correções extraídas do projeto consumidor e estabelece o comportamento reutilizável do plugin. Não incorpora dados editoriais, identidade visual, campos ACF nem credenciais do projeto de origem.

## Matriz de incorporação

| Correção ou necessidade observada | Tratamento reutilizável | Disponibilidade |
| --- | --- | --- |
| Home capturada em contexto de erro | Exigir HTTP 200, página inicial real e ausência de 404; recusar entrega incompatível | 4.1.2 |
| HTML de erro aceito como documento normal | Distinguir o documento 404 e exigir contexto e status correspondentes | 4.1.2 |
| Tema sem modelo de erro, com `index.php` renderizando a Home | Modelo de apoio com cabeçalho e rodapé do consumidor, sem editar arquivos do tema | 4.2.0 |
| Canonical ausente na Home que lista publicações | Completar o canonical somente na Home exportada, quando ausente; preservar canonical existente de SEO | 4.2.0 |
| Canonical ou diretiva de indexação inadequados no documento de erro | Remover canonical e assegurar `noindex` no 404 exportado | 4.2.0 |
| Hash de integridade incompatível após transformar CSS/JS | Calcular SHA-384 dos recursos tokenizados sobre os bytes finais da distribuição | 4.2.0 |
| Dependências de CDNs, fontes remotas e pistas de conexão | Inventário automático de HTML e CSS local, com distinção entre recursos e navegação | 4.2.0 |
| Publicação que precisa funcionar sem recursos externos | Controle opcional que impede a entrega com dependências identificadas ou inspeção incompleta | 4.2.0 |
| Instalação sem diagnóstico operacional | Diagnóstico automático no primeiro acesso ao painel da versão, com reexecução administrativa | 4.2.0 |
| Extensão ZIP ausente | Informar a capacidade real do ambiente; manter a alternativa do WordPress para o pacote estático e explicar a limitação do backup ampliado | Diagnóstico em 4.2.0; alternativa existente |
| Atualização automática dependente de cron | Explicar o requisito e alertar quando o agendador por visitas está desabilitado | Diagnóstico em 4.2.0; agendamento existente |
| Recursos omitidos da exportação | Descoberta de recursos do tema, tokens já presentes e dependências CSS | 3.0.1 e 4.0.0 |
| URLs erradas em subpastas e permalinks com controlador PHP | Reescrita configurável para domínio, subpasta ou arquivo | 3.0.1 |
| Paginação ou listagens desatualizadas após salvar uma página | Reconstrução completa para sincronizar páginas, arquivos e índice de pesquisa | Incorporado antes de 4.2.0 |
| Pesquisa dependente de WordPress | Índice e pesquisa local no navegador | 3.0.1 e 4.0.0 |
| Primeira publicação apenas de recursos, sem HTML | Exigir primeira entrega completa antes de liberar os outros escopos | 4.1.0 |
| Excesso de cópias administrativas | Manter somente as seis cópias reconhecidas mais recentes | 4.1.0 |
| Backup ou código de servidor exposto como recurso | Lista de tipos públicos, limites de diretório e bloqueio de arquivos privados | 4.0.0 |
| Saída anterior contaminando fontes, imagens ou downloads | Limpar buffers antes da resposta binária | 3.0.1 e 4.0.0 |
| Falha apresentada como publicação concluída | Propagar falhas de captura, gravação e entrega; preservar destino quando a captura é recusada | 3.0.1 e versões seguintes |
| Arquivos obsoletos no destino | Manifesto que distingue os arquivos gerados dos arquivos de terceiros | 3.0.1 |
| Classe `error404`, pesquisa e arquivo residuais | Filtro opcional que preserva classes próprias do consumidor | 4.1.2 |
| Remoção de CSS e classes quebrando outro tema | Manter a limpeza de classes e estilos de blocos desabilitada por padrão | 4.0.0 |
| Comentários internos e regras especulativas na publicação | Pós-processamento e validação antes da entrega | 4.0.0 |
| Avisos legais removidos na limpeza de CSS/JS | Preservação de licença, copyright e SPDX | 4.1.1 |
| Dependência de identificadores de outro produto | Namespace, API, opções, URLs administrativas, documentação e distribuição próprios | 4.0.0 |
| Atualização sobrescrevendo configuração anterior | Migração única e preservação dos valores existentes, inclusive `false`, `0` e vazios | 4.0.0 |
| Apresentação administrativa e escopos pouco claros | Caixas STATIC/GUARD e seletor independente, com explicações operacionais | 4.1.0 |

## Execução em uma nova instalação

1. Instalar e ativar o pacote público. A ativação registra os padrões e preserva configurações anteriores; não exporta o site nem gera SQL automaticamente.
2. Abrir **Gamb Static Guard**. O diagnóstico consulta apenas a Home e a rota de erro da própria origem, verifica contexto HTTP, capacidade de tokens, ZIP, tipos públicos, canonical e dependências. Não baixa bibliotecas de terceiros.
3. Configurar o destino e o caminho público da instalação. Não existe caminho, domínio ou tipo de conteúdo do projeto de origem fixado como padrão.
4. Executar **Converter & Publicar Agora**. A primeira publicação é completa. A exportação verifica todas as páginas processadas e suas folhas de estilo locais, completa o canonical ausente da Home, trata o documento de erro e atualiza o SRI dos recursos tokenizados materializados.
5. Conferir o diagnóstico e o resultado no navegador. Habilitar o requisito de dependências locais quando o projeto exigir essa condição. Nesse modo, dependências identificadas ou inspeção incompleta impedem a entrega.

O 404 de apoio aplica-se ao fallback `index.php` de temas clássicos sem `404.php`. Modelos próprios do tema, temas de blocos e modelos fornecidos por outras extensões são preservados. O visual herda fonte e cor do consumidor e não depende de Bootstrap, Flaticon ou fontes de terceiros.

## Ajustes que pertencem ao projeto consumidor

| Item | Motivo e aplicação |
| --- | --- |
| Campos ACF, calendário, reuniões, notícias e ordenação editorial | Nomes, chaves e regras variam entre projetos. Integrações devem ser adaptadores específicos; o plugin não cria nem altera esses dados |
| Título institucional e classes como `gaepe-home` | Identidade do consumidor; o plugin preserva o título e as classes existentes |
| Textos de reuniões e troca de meia-risca por hífen | Regra editorial de apresentação. Aplicação global alteraria textos legítimos de outros projetos |
| Breakpoints, cores, ícones, banners, componentes e responsividade | Dependem da arquitetura e do desenho do tema; não devem ser transplantados para a extensão |
| Adiamento de todo JavaScript e retirada de CSS nativo | Podem quebrar dependências ou temas de blocos; continuam configuráveis e sujeitos à verificação do consumidor |
| Internalização de Inter, Bootstrap, UIcons e GSAP | A versão, os complementos, os arquivos derivados, a licença e a estratégia de atualização devem ser identificados por projeto. O inventário facilita essa tarefa, mas não equivale a um espelho universal de qualquer CDN |
| SRI de uma CDN que permanece externa | Deve corresponder exatamente ao arquivo remoto escolhido. O plugin não inventa hashes nem substitui os de outra origem |
| Links institucionais e serviços externos | Navegação, mapas, vídeos, formulários e APIs podem depender legitimamente de outro sistema; documentar a necessidade ou fornecer uma alternativa local específica |
| Compartilhamento temporário na rede | Depende de servidor, IP, firewall e política de acesso; não é ativado pela instalação do plugin |
| Auditoria e arquivos ZIP de outro plugin de backup | Projeto independente; suas alterações não integram o Static Guard |

## Limites da inspeção

O diagnóstico administrativo inicial consulta até 2 MiB por resposta da origem. A análise de CSS lê até 32 arquivos locais, 1 MiB por arquivo e 8 MiB no total; registra até 200 referências distintas. A inspeção utiliza arquivos locais validados ou tokens resolvidos pelo plugin, sem buscar hosts externos. Ao atingir um limite, o relatório informa inspeção incompleta, e o modo que exige recursos locais recusa a entrega.

São examinados recursos declarados em HTML, `srcset`, estilos incorporados, `url()` e `@import` de CSS local. Não há execução de JavaScript no servidor: importações e requisições construídas dinamicamente exigem verificação no navegador. Um relatório sem referências identificadas não é uma certificação de funcionamento offline.

Relatórios e parâmetros sensíveis não integram o pacote estático. As consultas administrativas exigem `manage_options` e autenticação WordPress; o painel usa nonce. Credenciais, parâmetros e fragmentos de URLs de terceiros são omitidos dos relatórios persistidos.
