# Registro de versões

Este documento distingue a incorporação histórica das novas alterações. As datas correspondem ao registro verificável, sem reconstruir datas de commits inexistentes.

## 5.5.1 - 2026-11-26

- Gravação atômica com repetição limitada do estado de compartilhamento no Windows.
- Uma leitura de estado por requisição, evitando contenção e mistura de origens durante parar/reiniciar.
- Manual esclarece URL canônica estável, isolamento de cookies e Host preservado no proxy online.

## 5.5.0 - 2026-11-26

- Estático como padrão para novos perfis; URL canônica salva separada das origens temporárias autorizadas.
- Acesso original privado, desativado por padrão, com confirmação, sessão administrativa, nonce, expiração de uma hora e aviso fixo.
- Destino e endereços reorganizado em endereço completo, validação privada e entrega; bloqueio de domínio ancestral preservado.
- Scripts de compartilhamento LAN e cadastro opcional de origem HTTPS sem alterar home/siteurl ou outros projetos.
- Corrige cabeçalho de cache inválido que causava HTTP 500 no front autenticado.
- Manual, testes de isolamento de origem e controles de acesso atualizados; correções 5.4.1 incorporadas.

## 5.4.1 - 2026-11-25

- Restaurar a compatibilidade com PHP 7.4 e incluir essa vers?o na matriz de CI.

- Reorganizar o painel de destino, manter a ação de salvar disponível e vincular alertas às abas de diagnóstico e auditoria.
- Usar a URL da instalação como base do destino padrão, bloquear o domínio pai e impedir caminhos acima da instalação no mesmo host. Exigir HTTPS em destinos externos.

- Usar o PclZip do WordPress com zlib para o backup de banco, tema e extensões quando a extensão ZIP do PHP estiver indisponível.
- Atualizar o diagnóstico para indicar o suporte ao backup completo. O backup apenas do banco continua disponível em SQL sem ZIP nativo.

## 5.4.0 - 2026-11-24

- Adicionar a aba Auditoria com verificações defensivas de leitura da última entrega local e de até quatro URLs derivadas do destino salvo, sem redirecionamentos ou entrada de URLs arbitrárias.
- Separar publicação, exportação e servidor; registrar esperado, observado, correção, quatro estados e referência parcial ao ASVS 5.0.0.
- Conferir canonical/robots, SRI de CSS local, MIME/nosniff de PDF/DOCX, referências internas, arquivos indevidos e headers; preservar análise local quando o host não responde e não presumir existência de WAF.
- Consultar somente avaliações/relatórios existentes do Tenable WAS, vinculados ao alvo/ambiente, com chaves somente no servidor; preservar o resultado local em falhas de integração. Cymulate permanece pendente, sem chamadas.
- Permitir escolher no admin WordPress ou publicação estática na URL principal, com facilitador do endereço local, entrega vinculada a destino/ambiente, admin/API/captura preservados e nenhum ajuste global de servidor.
- Consolidar os históricos equivalentes das branches antigas sem reintroduzir código já substituído; manter proteção da main e validações obrigatórias.

## 5.3.0 - 2026-11-24

- Separar configurações e evidências em Análises e auditoria, com relatórios independentes da origem e publicação, controles executados, documentos, correções e downloads administrativos protegidos.
- Explicar os alertas STATIC e distinguir unidades processadas do inventário físico, com localização, paginação e links públicos permitidos.
- Destacar dependências e navegação externa, limites de inspeção e providências; acrescentar ajuda e exemplos aos campos.
- Verificar e criar explicitamente pastas dedicadas, reutilizando a validação da entrega e bloqueando caminhos protegidos, atalhos e marcadores de aplicação.
- Disponibilizar endereço público persistente e manual simplificado, sem dados operacionais, na origem e na exportação.
- Exibir as datas dos relatórios no fuso configurado no WordPress, identificado na interface; preservar timestamps ISO nos dados.
- Incluir correções de canonical absoluta, reescrita de origem, preview/noindex, SRI final, CORS e MIME de anexos, mantendo tokens e tratamento dos recursos.
- Incluir pausa/continuação cooperativa da mesma sincronização, cabeçalho de progresso compacto e área fixa com rolagem interna responsiva.

## 5.2.0 - 2026-11-23

- Substituir os rótulos fixos de STATIC e GUARD por estados derivados do diagnóstico e da auditoria, com tratamento de impedimentos, alertas, ausência de verificação e revalidação pendente.
- Atualizar as badges no HTML inicial e na consulta de estado após operações, sem alterar as permissões administrativas ou executar diagnósticos durante a leitura.
- Carregar somente os estilos do menu lateral em todas as telas administrativas e manter os estilos e scripts do painel restritos ao seu contexto.
- Padronizar os controles Baixar e Excluir dos backups, com espaçamento de 8 px, alinhamento e quebra de linha, preservando a confirmação de exclusão.
- Incorporar os ajustes locais do painel e tornar o cabeçalho responsivo com CSS próprio, sem depender de classes Bootstrap ausentes.
- Restringir as regras do menu ao próprio plugin e eliminar sua duplicação na folha do painel.

## 5.1.1 - 2026-11-19

- Corrigir a composição das consultas de progresso quando a base REST utiliza `?rest_route=`, preservando os parâmetros da rota e do identificador da operação.
- Validar a navegação, a execução e o percentual no navegador contra os endpoints nativos em modo de links permanentes simples, sem alterar a configuração do site consumidor.

## 5.1.0 - 2026-11-19

- Integrar o monitor da versão WordPress, com configuração de IPs exatos e proxies confiáveis, normalização IPv4/IPv6 e resposta JSON protegida contra cache.
- Preservar as rotas de compatibilidade do monitor 3.3.2 quando habilitado; manter o recurso desabilitado e sem IPs institucionais nos padrões da distribuição.
- Incorporar as alterações locais da folha de estilos e converter os botões laterais em abas navegáveis, com seleção, hover e foco nativo preservado.
- Adotar três accordions exclusivos, etiquetas compactas para HTML/ZIP e Blindado e monitor de revisão e destino da publicação.
- Acrescentar acesso direto às releases do GitHub no cabeçalho do painel.
- Registrar progresso por etapas e unidades no servidor, com barra persistente, percentual no título, retomada após recarregamento, recusa de concorrência e identificação de falhas.
- Instrumentar publicação, configuração, diagnóstico, consulta de atualizações, auditoria e backups; preservar a retenção das seis cópias mais recentes.
- Ampliar regressões de IP, proxy, execução concorrente, repetição de pedidos, interrupções e sincronização editorial.

## 5.0.1 - 2026-11-18

- Incorporar o ajuste visual existente na instalação consumidora ao menu administrativo: alinhamento do ícone, opacidade, espaçamento e identificação do item.
- Reorganizar a folha de estilos para manutenção, preservando as regras anteriores do painel.
- Atualizar os metadados de distribuição e disponibilizar pacote instalável com resumo SHA-256 próprio.

## 5.0.0 - 2026-11-16

- Requerer PHP 8.1 e adotar strict_types em todos os arquivos PHP distribuídos.
- Introduzir parser HTML5 no PHP 8.4 e alternativa DOMDocument com mb_encode_numericentity, preservando Unicode em scripts e estilos.
- Auditar buffer original e pacote final contra vazamentos, código bruto, credenciais, shortcodes configurados e contexto 404 acidental na Home.
- Sanitizar padrões de classes, estilos, variáveis nativas, comentários, regras especulativas e pontuação textual, com whitelist explícita de estilos.
- Calcular SRI externo HTTPS com cache de sete dias, preconnect, noopener, noreferrer e defer local.
- Restringir display_errors no escopo de captura e registrar avisos sem mensagens potencialmente sensíveis.
- Integrar GitHub Releases ao atualizador nativo, com cache de doze horas, modal de versão, token AES-256-GCM ou constante e download validado sem vazamento do PAT nos redirecionamentos.
- Normalizar diretório extraído, invalidar SRI e agendar revalidação após atualizações.
- Distribuir bin/audit-static.php e ampliar a matriz PHP 8.1, 8.2, 8.3 e 8.4.

## 4.2.0 - 2026-11-13

- Extrair as correções reutilizáveis do consumidor e documentar a matriz de portabilidade e os limites de automação.
- Acrescentar diagnóstico inicial e reexecução administrativa, com inventário de dependências externas em HTML e CSS local.
- Distinguir links de navegação das dependências; omitir credenciais e parâmetros de URLs persistidas.
- Oferecer modelo 404 de apoio para temas clássicos sem modelo próprio, preservando temas de blocos e modelos de outras extensões.
- Completar canonical ausente da Home exportada e assegurar noindex sem canonical no documento de erro.
- Calcular SRI de estilos e scripts tokenizados sobre os arquivos finais da exportação.
- Oferecer bloqueio opcional de publicação com dependências externas identificadas ou inspeção incompleta.
- Ampliar regressões e testar a HTML API oficial do WordPress 6.2 e da distribuição estável corrente.
- Atualizar o metadado de versão da instalação sem reaplicar o perfil nem sobrescrever configurações.

## 4.1.2 - 2026-11-13

- Recusar captura da Home com HTTP incorreto ou contexto de erro, confirmando is_front_page e is_404 na origem por cabeçalho solicitado com assinatura.
- Exigir HTTP 404 real para o documento de erro e distinguir sua captura das páginas normais.
- Acrescentar error404 e variações nativas de pesquisa e arquivo à limpeza opcional das classes do corpo.
- Acrescentar regressões para Home, soft 404, documento de erro e classes próprias do consumidor.

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
