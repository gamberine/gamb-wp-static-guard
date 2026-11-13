# Registro de validação

## Versão 4.1.2 — 13/11/2026

Foram concluídas 40 verificações independentes. As novas regressões abrangem a rejeição de captura da Home sem contexto autenticado, com erro lógico ou com status HTTP incompatível; o documento de erro exige HTTP 404 e contexto de erro real. As classes nativas de erro, pesquisa e arquivo são removidas, preservando as classes próprias do tema consumidor.

Na integração WordPress, a Home respondeu com HTTP 200, `is_front_page() = true` e `is_404() = false`; a rota inexistente respondeu com HTTP 404 e contexto de erro. A exportação completa processou 206 arquivos. Os 398 registros de conteúdo e respectivos metadados editoriais permaneceram inalterados; a retenção permaneceu em seis cópias. O modelo visual da página de erro pertence ao tema consumidor.

A publicação preserva o título e a classe da Home e converte o canonical para o endereço estático configurado. As bibliotecas locais do consumidor foram preservadas. Esses resultados descrevem o ambiente de integração observado, sem substituir homologação em outros ambientes.

## Registro anterior — versão 4.1.0

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
