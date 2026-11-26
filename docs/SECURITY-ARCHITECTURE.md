# Arquitetura de sanitização, auditoria e atualização

Versão 5.0.0, registrada em 16/11/2026. O requisito mínimo passa a PHP 8.1. O núcleo WordPress continua sendo responsável por autenticação, autorização, instalação e controle das atualizações automáticas.

## Fluxo e pontos de bloqueio

1. A captura da origem utiliza um cabeçalho autenticado por HMAC. Somente nesse escopo `display_errors` é desabilitado. Avisos registram categoria, arquivo-base e linha no registro interno; o conteúdo da mensagem é omitido para evitar exposição de credenciais. A configuração e o handler são restaurados ao final do escopo.
2. A captura autenticada entrega o buffer original ao motor, preservando evidências que uma limpeza anterior poderia ocultar. O gate verifica erros PHP e SQL, caminhos de sistema, código bruto, comentários de blocos não processados, identificadores sensíveis, endpoints dinâmicos, textos de desenvolvimento e shortcodes configurados.
3. A Home exige HTTP 200, contexto de página inicial e ausência de 404. A auditoria também rejeita título, classe ou marcador de erro na Home. O documento 404 deliberado permanece permitido.
4. O sanitizador aplica regras por padrões no DOM, complementadas por regex em CSS e marcadores de origem. A whitelist de IDs de estilos é explícita. Comentários HTML, classes nativas e regras especulativas são removidos; propriedades próprias do consumidor são preservadas.
5. O gate valida o documento antes de gravar o HTML. Depois da materialização e do cálculo de SRI local, todos os documentos do pacote são auditados novamente antes da entrega. Falhas registram severidade CRITICAL e mantêm o destino anterior.

Referências a recursos públicos internos são resolvidas pelo mecanismo de tokens existente. Referências sem correspondência permanecem impedimentos; não são substituídas por endereços fictícios. A inspeção não executa JavaScript e não constitui garantia de ausência de toda impressão digital futura.

Os relatórios armazenam códigos de regras e fontes reduzidas, sem copiar o trecho sensível. Acesso administrativo requer `manage_options`; o cliente utiliza nonce WordPress. Modificar repositório ou token e verificar atualizações exige também `update_plugins`.

## Parser e preservação de conteúdo

No PHP 8.4, `Dom\HTMLDocument::createFromString` interpreta HTML5. Do PHP 7.4 ao 8.3, `DOMDocument` usa erros libxml internos, bloqueio de rede e `mb_encode_numericentity`, sem a conversão depreciada HTML-ENTITIES. Não são habilitados expansão de entidades nem carregamento de DTD externo.

O conteúdo raw-text de script e style é preservado durante a conversão e serialização para que caracteres Unicode não sejam transformados em entidades dentro do código executável. A normalização tipográfica aplica-se a textos e atributos textuais; URLs, CSS e handlers conservam sua semântica. Os arquivos PHP distribuídos usam strict_types e propriedades declaradas.

## Recursos externos

Folhas de estilo e scripts externos HTTPS recebem SHA-384 e crossorigin quando o hash necessário está ausente. O CSS dinâmico de fonts.googleapis.com é excetuado. As consultas utilizam `wp_safe_remote_get`, validação de URL, nenhum redirecionamento automático, timeout de 15 segundos e limite de 5 MiB. Falha na conferência interrompe a publicação em vez de inventar um hash. O cache é atrelado à URL por sete dias.

Preconnect é gerado para as origens de scripts e estilos externos detectados. Links com target blank recebem noopener e noreferrer. Scripts locais sem async ou defer recebem defer. Dependências de ordem com JavaScript inline precisam ser verificadas no tema consumidor. A hospedagem local e o requisito opcional de recursos locais continuam disponíveis; SRI não transforma um serviço externo em recurso offline.

O fornecedor deve permitir CORS para carregar um recurso externo com SRI. Recursos variáveis por localização, agente de usuário ou tempo podem exigir internalização ou seleção de uma versão imutável. A whitelist de estilos não isenta vazamentos, credenciais nem referências internas não resolvidas.

## Atualizador GitHub

- Repositório padrão gamberine/gamb-wp-static-guard, configurável por owner/repo ou constante GAMB_GITHUB_REPOSITORY.
- Consulta de release estável com cache de doze horas. Drafts e prereleases são ignorados. A versão é comparada com version_compare.
- Seleção de asset ZIP, priorizando o nome de distribuição; zipball validado funciona como alternativa. Outros plugins e pacotes não aprovados pelo release não são interceptados.
- Token opcional por GAMB_GITHUB_TOKEN ou opção separada com AES-256-GCM, nonce aleatório e chave derivada dos salts. A constante tem precedência. O token nunca integra respostas REST nem o pacote público.
- Downloads privados utilizam o endpoint de assets da API. Authorization é enviado somente à API GitHub no caminho do repositório configurado. Cada redirecionamento é validado e feito sem propagação do PAT para GitHub CDNs.
- HTTPS, limite de redirecionamentos, limite de 100 MiB, assinatura ZIP e SHA-256 do asset quando o GitHub o disponibiliza são conferidos. Esses controles não substituem assinatura criptográfica independente do fornecedor ou proteção da conta GitHub.
- A pasta extraída é normalizada com a API de filesystem do WordPress, dentro da área de extração, mantendo gamb-wp-static-guard como identidade. Fonte externa à extração ou sem arquivo principal é recusada.
- O modal de detalhes apresenta o texto da release escapado. HTML recebido do GitHub não é executado.
- Atualizações de núcleo, tema ou plugin invalidam a geração de hashes e agendam revalidação de leitura da origem. Um aviso permanece disponível quando a verificação está pendente ou falhou. A auditoria não publica conteúdo automaticamente.

A ativação de atualizações automáticas permanece uma escolha feita no painel nativo do WordPress. A instalação inicial da versão 5 em uma instalação que ainda não possua o atualizador requer o ZIP. Não é necessário editar wp-config.php para usar o repositório público.

Redes corporativas podem inspecionar TLS. No Windows, quando o libcurl oferece CURLSSLOPT_NATIVE_CA, os pedidos próprios do atualizador também utilizam os certificados já confiados pelo sistema operacional. A validação de cadeia e de hostname permanece habilitada. A constante opcional GAMB_GITHUB_CA_BUNDLE admite um bundle corporativo legível; não é distribuído certificado privado no pacote e não se desabilita sslverify. A API GitHub também impõe limites de consulta por IP; um PAT opcional aumenta o limite para ambientes com IP compartilhado.

## CLI e operação

```sh
php bin/audit-static.php --wp-root=/caminho/wordpress --directory=/caminho/publicacao
php bin/audit-static.php --wp-root=/caminho/wordpress --revalidate
```

O primeiro comando inspeciona arquivos sem alterá-los. O segundo consulta a origem, sem entrega. O plugin deve estar ativo na instalação indicada. JSON é escrito no stdout, com códigos de saída 0 para aprovação, 1 para impedimento e 2 para uso inválido. O relatório administrativo é atualizado.

Para migrar, preserve o pacote anterior e a configuração, confira as extensões PHP, homologue os estilos autorizados e a ordem dos scripts e publique o site completo. O rollback consiste em reinstalar a distribuição anterior e, quando necessário, restaurar a cópia de publicação anterior. O banco e os arquivos do tema não são reestruturados pela migração.

## Riscos tratados e limites

| Evidência ou vetor | Classificação | Controle | Referência |
| --- | --- | --- | --- |
| Erro ou segredo incluído no buffer | CRITICAL, confiança alta | Gate antes da limpeza e da entrega, sem armazenar o trecho | CWE-209, CWE-200 |
| HTML bruto e código não resolvido | CRITICAL, confiança alta | Regras configuráveis e inspeção de contexto | CWE-209 |
| Fetch remoto de SRI | Risco elevado | API HTTP segura, HTTPS, limite e redirecionamento desabilitado | CWE-918 |
| PAT exposto em opção ou CDN | Risco elevado | AES-GCM e autenticação limitada ao host e repositório | CWE-312, CWE-200 |
| Pacote ou diretório substituído | Risco elevado | Release validado, limites, digest disponível e confinamento da extração | CWE-494, CWE-22 |
| Conteúdo da release no modal | Risco elevado | Escape e filtragem de HTML | CWE-79 |
| Nova versão do CMS ou PHP | Risco de regressão | Hooks, DOM, gate final, revalidação e matriz de testes | Validação contínua |

Os padrões podem recusar exemplos de código ou textos técnicos publicados intencionalmente. Exceções editoriais devem ser analisadas no consumidor, preservando a eficácia do gate. Versões futuras de PHP e WordPress dependem de ensaio contínuo; a arquitetura não oferece garantia irrestrita de compatibilidade ou de anonimização do CMS.

## Referências primárias

- [Parser HTML5 do PHP](https://www.php.net/manual/en/dom-htmldocument.createfromstring.php).
- [Diferenças de conformidade entre DOM legado e moderno](https://wiki.php.net/rfc/opt_in_dom_spec_compliance), incluindo retorno null de atributos ausentes.
- [Seleção de origem do atualizador WordPress](https://developer.wordpress.org/reference/hooks/upgrader_source_selection/).
- [Interceptação de download do atualizador WordPress](https://developer.wordpress.org/reference/hooks/upgrader_pre_download/).
