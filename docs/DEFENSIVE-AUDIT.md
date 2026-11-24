# Auditoria defensiva da última exportação

A aba **Análises e auditoria → Auditoria** reutiliza o destino, manifesto, estados de operação, bloqueio de concorrência e estilos existentes. **Executar auditoria** faz somente leituras. Persiste apenas evidências administrativas e progresso; não exporta, corrige arquivos, inicia scans, solicita geração de relatórios ou modifica o host.

## Escopo

- Home e uma página interna do manifesto: canonical única e absoluta no host/caminho esperado; robots conforme o ambiente. São critérios de publicação, separados dos achados de servidor e sem alegação de requisito ASVS para SEO.
- CSS local referenciado nessas páginas: compara os hashes SRI com bytes finais. SHA-384 é esperado quando o recálculo está ativo. Recálculo desligado permite ausência de hash, com atenção; desativação explícita permanece visível. Não há fallback automático de integridade.
- Nomes dos arquivos, inclusive arquivos fora do manifesto: procura backups, logs, configuração, metadados e caminhos internos. Não abre arquivos suspeitos. Em HTML/CSS/JS, registra apenas o tipo de referência interna/localhost e caminho relativo; nenhum trecho ou segredo é incluído.
- Até quatro GETs derivados da URL salva e do manifesto: duas páginas e um PDF/DOCX existente por `/v/<token>`. Timeout de 6 segundos, sem redirects, autenticação ou cookies. Não consulta URLs extraídas do HTML e não aceita URL enviada pelo botão.
- Registra status HTTP e headers de uma lista explícita. Inspeciona MIME/nosniff, robots, CSP, HSTS, referrer policy, framing, COOP e exposição de versão. Headers de CDN/proxy são apenas **Indício observado**; **WAF não confirmado** permanece até confirmação administrativa do provedor.

Os controles de servidor usam como referência parcial o [OWASP ASVS 5.0.0](https://github.com/OWASP/ASVS/tree/v5.0.0/5.0): V3.4.1, V3.4.3–V3.4.6, V3.4.8 e V13.4.1/V13.4.6/V13.4.7. V3.6.1 trata recursos externos; a comparação SRI local aplica o mesmo princípio por analogia, sem declarar cobertura integral do requisito. A aba não oferece certificação ASVS nem teste de invasão.

Leituras de texto são limitadas a 5 MB por arquivo e 32 MB no conjunto, com até 20.000 entradas; SRI permite até 50 declarações locais por página e CSS de até 10 MB. Limites atingidos aparecem como **Não verificado**. A inspeção HTTP continua independente da análise local; indisponibilidade de DNS/TLS/rede não apaga a evidência dos arquivos.

O relatório identifica a data da auditoria, versão, pasta/URL/ambiente, data da última execução registrada e SHA-256 do manifesto efetivamente encontrado. Se o registro de entrega não corresponde ao destino salvo, apresenta atenção; datas antigas não comprovam qual configuração gerou o pacote. Destinos ZIP não são extraídos automaticamente.

## Acesso

As rotas administrativas `destination-audit` e `audit-connectors` exigem `manage_options`. Os POSTs validam nonce REST explicitamente e usam `Operation_Progress` para impedir sobreposição com publicação, configuração e outras operações. O resultado é privado ao administrador; não é incorporado ao manual público.

O modo de validação de caminho para leitura mantém os bloqueios de raiz, áreas internas, junções e travessia. Permite identificar marcadores de aplicação que foram copiados indevidamente ao destino. Esse modo não cria pastas e não é utilizado para escrita ou entrega.

## Tenable WAS

Conector opcional, desligado por padrão. Configure no servidor as constantes ou variáveis de ambiente `GAMB_TENABLE_ACCESS_KEY` e `GAMB_TENABLE_SECRET_KEY`. Não há campos de chave no navegador nem persistência dessas chaves nas opções ou relatórios. Utilize uma conta limitada à leitura, com `WAS.SCAN_WAS.READ` e **Can View** para a avaliação. O administrador deve confirmar esses privilégios; o plugin não consegue provar ausência de privilégios excedentes.

Na aba, informe o UUID de uma avaliação já concluída e salve a associação à URL/ambiente atuais. Mudar o destino ou ambiente exige nova associação explícita. Nenhum hostname de API arbitrário é aceito. São realizados apenas:

1. [`GET https://cloud.tenable.com/was/v2/scans/{scan_id}`](https://developer.tenable.com/reference/was-v2-scans-details), para conferir alvo/ID/status e data.
2. [`GET https://cloud.tenable.com/was/v2/scans/{scan_id}/report`](https://developer.tenable.com/reference/was-v2-scans-download-export), para um JSON de relatório **já existente**. Não há PUT de geração, POST de lançamento ou polling.

O conector aceita o alvo apenas se esquema, host, porta e caminho corresponderem ao destino associado. Guarda apenas resumo estruturado por severidade, identidade, alvo e datas. Não armazena requests, responses, evidências brutas ou credenciais presentes no relatório. Formato desconhecido, falta de data de avaliação, credencial, permissão, relatório, timeout e limite da API resultam em **Não verificado**, com motivo e preservação da auditoria local. Dados externos têm sua própria data de avaliação e de geração do arquivo, distintas da consulta atual; não certificam a exportação atual.

As consultas do provedor têm timeout de 8 segundos, HTTPS verificado, redirects desativados e limite de 2 MB por resposta.

## Cymulate

Permanece pendente e não realiza chamadas nesta versão, conforme solicitado. A [documentação de API](https://api.app.cymulate.com/docs/) exige acesso autenticado; o endpoint de leitura e os privilégios da conta não foram confirmados. A habilitação futura depende dessa confirmação e de adaptação testada do contrato, sem alterar o fluxo local.

## Validação

`tests/destination-audit.php` cobre preview, produção indexável/noindex inesperado, destino indisponível, redirecionamentos, SRI divergente/desligado, MIME, versão, proxy/WAF, arquivos suspeitos, contexto inválido e falhas de integração. As respostas externas são fixtures: uma conta Tenable com relatório real ainda é necessária para homologar a integração com o tenant. Não são criadas avaliações reais nos testes.
