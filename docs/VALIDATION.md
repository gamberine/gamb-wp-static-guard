# Registro de validação

## Versão 5.2.0 - 23/11/2026

Foram concluídas 208 verificações independentes em PHP 8.3 e 225 com a HTML API do WordPress instalado. As regressões adicionais cobrem ausência de diagnóstico ou auditoria, alertas, impedimentos, relatórios de outra versão, revalidação pendente, auditoria sem documentos e separação dos estilos globais do menu em quatro contextos administrativos. A leitura das badges não altera as opções de diagnóstico.

A suíte JavaScript verifica atualização das três badges, transição de impedimento para sucesso, URL e nomes acessíveis dos controles de backup, cancelamento da exclusão, recusa durante outra operação e lista vazia. A integração contínua executa essa suíte junto à distribuição, além das matrizes PHP 8.1 a 8.4 e HTML API do WordPress 6.2 e versão estável corrente.

A prévia usa o markup candidato do painel, as folhas nativas do WordPress e dados fictícios de backups. A renderização Chromium em 1440 e 390 px confirmou ausência de transbordamento na página, cabeçalho horizontal/vertical, logotipo carregado e botões com a mesma altura e gap de 8 px. A tabela mantém rolagem horizontal própria em telas estreitas. Essa conferência não constitui auditoria integral de acessibilidade nem validação de todos os fluxos administrativos.

Os 22 arquivos PHP de distribuição e testes passaram pela análise de sintaxe. O pacote é produzido pelo gerador determinístico existente; o download publicado e a instalação consumidora devem ser conferidos por hash no fechamento da implantação. Nenhuma opção editorial, destino de publicação, cópia de banco ou configuração de rede integra a distribuição.

## Versão 5.1.1 - 19/11/2026

Mantidas as 172 verificações independentes e 189 com a HTML API. O navegador utilizou a base REST em formato `?rest_route=` contra o WordPress real, conferindo carregamento, navegação e acompanhamento de uma operação com identificador no parâmetro de consulta. Não foi alterada a estrutura de links permanentes do consumidor.

## Versão 5.1.0 - 19/11/2026

Foram concluídas 172 verificações independentes e 189 com a HTML API do WordPress instalado. Os novos cenários abrangem IPs exatos, IPv4 mapeado em IPv6, cadeias de proxy, spoofing de X-Forwarded-For, validação atômica da configuração, reserva por usuário, concorrência, repetição, histórico, interrupção e revisão da publicação por destino.

A integração WordPress confirmou os endpoints principal e de compatibilidade, resposta JSON autorizada, recusa de origem não autorizada mesmo diante de resposta antecipada de cache e cabeçalhos no-store. A interface foi conferida em 1440 e 390 px, com abas, etiquetas, exclusividade dos accordions, barra e percentual no título. A exportação utilizou um destino isolado e concluiu 213 arquivos processados, com consulta simultânea de percentuais intermediários. A cópia SQL de teste utilizou diretório isolado, preservando os backups existentes.

O percentual mede etapas executadas, não tempo restante. A extensão ZIP nativa não estava disponível no ambiente local; a cópia de banco usou SQL. A matriz de integração contínua mantém PHP 8.1 a 8.4 e WordPress 6.2 e distribuição estável corrente. Consulte o resultado do commit no GitHub Actions e os limites em VERSION-MONITOR.md.

## Versão 5.0.1 - 18/11/2026

Pacote de manutenção destinado à incorporação do CSS administrativo existente na instalação consumidora. A comparação preserva as regras anteriores e identifica três regras adicionais relativas ao menu. Os metadados de versão são atualizados em conjunto.

A validação local concluiu 128 verificações independentes e 145 com a HTML API do WordPress instalado, além da análise de sintaxe de 18 arquivos PHP e da conferência dos 23 arquivos do ZIP. A comparação CSSOM em Chromium preservou as 34 regras anteriores e identificou as três regras adicionais. O banco de dados local estava indisponível nesta rodada; o acesso autenticado ao painel não foi repetido. A integridade SHA-256 é conferida após o download do pacote publicado. O resultado da matriz de compatibilidade permanece disponível no GitHub Actions do commit correspondente.

## Versão 5.0.0 - 16/11/2026

A suíte possui 128 verificações independentes e 17 adicionais com a HTML API oficial, totalizando 145 verificações por distribuição WordPress ensaiada. Os testes abrangem as regras de vazamento, contexto 404, shortcodes, whitelist, Unicode em JavaScript, SRI remoto e cache, recusa de SSRF, token AES-GCM adulterado, seleção de release, isolamento de atualização em lote, download com redirecionamento sem propagação do PAT e normalização de diretório.

A matriz de integração contínua passa a PHP 8.1, 8.2, 8.3 e 8.4, com DOM, mbstring e OpenSSL. PHP 8.4 exercita o parser HTML5; as demais versões exercitam a alternativa DOMDocument. Os testes adicionais utilizam WordPress 6.2 e a distribuição oficial estável corrente. A compatibilidade com versões posteriores depende dos resultados futuros da matriz, sem garantia irrestrita.

Na integração WordPress local, a primeira exportação da versão processou 206 arquivos e preservou os 398 registros e metadados editoriais, com seis backups mantidos. Os resultados adicionais de navegação, gate controlado, CLI e atualização são registrados no relatório privado da rodada. A auditoria não altera os conteúdos ou arquivos do tema consumidor.

## Versão 4.2.0 - 13/11/2026

Foram concluídas 56 regressões independentes e 17 verificações adicionais utilizando o parser da HTML API oficial: 73 verificações com o WordPress 6.2 e 72 com a distribuição instalada no ambiente de integração. A integração contínua também executa essa suíte sobre a distribuição oficial estável corrente.

Os novos cenários abrangem seleção restrita do 404 de apoio, preservação de modelos de blocos e de outras extensões, detecção de origens externas, omissão de dados sensíveis das referências, canonical ausente e existente, diretivas do documento de erro, inventário de recursos e navegação, CSS relativo sob token com importação circular e SRI calculado sobre os bytes finais.

Na instalação consumidora, a exportação completa processou 206 arquivos, preservou os 398 registros e metadados editoriais e manteve seis backups. Foram conferidas 377 referências SRI em 22 documentos HTML, correspondentes a 20 arquivos distintos, sem divergência de hash. O painel de diagnóstico foi utilizado com autenticação administrativa em 1440 e 390 px, incluindo atualização do relatório e conferência dos quatro novos controles.

O navegador conferiu Home e erros na origem, na publicação local, no celular e na prévia de rede, com bloqueio dos hosts externos: nenhum recurso remoto solicitado ou erro de execução observado. Os 86 endereços locais consultados responderam sem falha. O modelo 404 próprio do tema consumidor foi preservado; os critérios de seleção do fallback genérico foram validados pelas regressões. Esses ensaios não constituem homologação de todos os temas, CDNs ou serviços possíveis.

## Versão 4.1.2 - 13/11/2026

Foram concluídas 40 verificações independentes. As novas regressões abrangem a rejeição de captura da Home sem contexto autenticado, com erro lógico ou com status HTTP incompatível; o documento de erro exige HTTP 404 e contexto de erro real. As classes nativas de erro, pesquisa e arquivo são removidas, preservando as classes próprias do tema consumidor.

Na integração WordPress, a Home respondeu com HTTP 200, `is_front_page() = true` e `is_404() = false`; a rota inexistente respondeu com HTTP 404 e contexto de erro. A exportação completa processou 206 arquivos. Os 398 registros de conteúdo e respectivos metadados editoriais permaneceram inalterados; a retenção permaneceu em seis cópias. O modelo visual da página de erro pertence ao tema consumidor.

A publicação preserva o título e a classe da Home e converte o canonical para o endereço estático configurado. As bibliotecas locais do consumidor foram preservadas. Esses resultados descrevem o ambiente de integração observado, sem substituir homologação em outros ambientes.

## Registro anterior - versão 4.1.0

Data: 13/11/2026. Versão: 4.1.0. Ambiente de integração: WordPress 7.1.4, PHP 8.3.26, Apache e navegador Chromium.

## Verificações independentes

Foram concluídas 28 verificações em `tests/run.php`, abrangendo migração de configurações com preservação de valores, manutenção de classes próprias do consumidor, resolução e rejeição de tokens, travessia de diretórios, tipos de arquivo, comentários, regras especulativas, referências internas residuais, endereços de publicação, primeira conversão completa e retenção das seis cópias mais recentes. Arquivos externos ao padrão administrado foram preservados.

A integração contínua executa sintaxe e regressões em PHP 7.4, 8.1, 8.3 e 8.4. O resultado de cada commit deve ser consultado no histórico de Actions; esse teste isolado não substitui homologação WordPress em todas essas versões.

## Integração observada

| Critério | Resultado |
| --- | --- |
| Painel autenticado | Dois accordions separados, escopo fora dos accordions, opções na ordem prevista, seta e comando de publicação disponíveis |
| Visualização móvel | Painel conferido em 390 px, sem transbordamento horizontal |
| Primeiro processamento | Solicitação de tema convertida pelo servidor em publicação completa; estado liberado após entrega bem-sucedida |
| Cópias administrativas | Redução de 16 para 6 arquivos reconhecidos, seguida de novas cópias sem exceder o limite |
| API sem autenticação | Consulta administrativa recusada com HTTP 401 |
| API com entrega inválida | Entrada recusada com HTTP 400 |
| Publicação de integração | 174 arquivos processados; 22 documentos HTML, incluindo a página de erro |
| Navegação e recursos locais | 71 endereços consultados, sem resposta de falha |
| Conteúdo do consumidor | 363 registros de conteúdo e ACF preservados; bloqueio transitório de edição excluído da comparação |
| Navegador | Origem, publicação local e prévia de rede sem erros de execução, imagens quebradas ou referências ARIA sem alvo nos cenários conferidos |
| Pesquisa | Índice `search-index.json` gerado; nomenclatura anterior ausente da publicação |

## Controles de segurança verificados

As barreiras de caminho e de tipo de arquivo tratam riscos de leitura e exclusão fora do diretório autorizado (CWE-22). A API exige capacidade administrativa (CWE-862). Mensagens administrativas e resultados de pesquisa utilizam APIs DOM e escape de contexto para reduzir inserção de marcação (CWE-79). A validação final recusa HTML com referências internas não resolvidas. Essas associações descrevem controles verificados, sem declarar certificação ou ausência integral de vulnerabilidades.

## Limitações

O ensaio de integração utiliza um tema consumidor. Não demonstra compatibilidade universal com temas, extensões, campos de pesquisa, aplicações dinâmicas ou configurações de servidor. A extensão ZIP não estava disponível no PHP local: a exportação estática foi validada com a alternativa do WordPress e as cópias do banco em SQL; o pacote de banco, tema e extensões exige ZIP e não foi executado nesse ambiente.

Os testes de interface abrangeram foco visível, semântica dos controles, nomes, referências ARIA, menu móvel e ausência de transbordamento nos cenários citados. Não constituem auditoria integral WCAG ou e-MAG. A restauração das cópias não integra este ensaio. Alterações do tema consumidor e seus relatórios são mantidos separadamente do plugin.
