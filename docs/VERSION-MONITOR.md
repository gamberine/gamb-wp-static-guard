# Monitor da versão WordPress e acompanhamento operacional

## Configuração e compatibilidade

O painel **GUARD/Segurança → Monitor da versão WP** incorpora o comportamento do
monitor 3.3.2. Habilite o recurso e informe IPs exatos. Novas instalações mantêm o
monitor desabilitado, sem IPs e sem proxies previamente autorizados. Nenhum IP de
uma instituição integra os padrões do pacote.

O endpoint principal é `/wp-json/gamb-wp-static-guard/v1/wordpress-version`.
Também são disponibilizados `/wp-json/monitor-versao-wp` e
`/wp-json/monitor-versao-wp/check` quando o monitor está habilitado. Sem links
permanentes, utilize `?rest_route=/gamb-wp-static-guard/v1/wordpress-version`.
A resposta autorizada contém somente `wordpress_version`. A rota histórica
`/meu-sistema/v1/versao` permanece removida quando a integração está habilitada.

Lista vazia bloqueia todas as origens. IPv4 mapeado em IPv6 é normalizado para a
mesma identidade. CIDR, portas, nomes de host e curingas são recusados. A política
de IP se aplica também aos administradores autenticados. O painel de configuração
e as consultas administrativas exigem `manage_options` e autenticação REST.

`X-Forwarded-For` só é considerado se `REMOTE_ADDR` corresponder a um proxy
explicitamente confiável. A cadeia é percorrida da direita para a esquerda até o
primeiro salto não confiável, com limite de 20 saltos e 2.048 bytes. Proxies não
são autorizados como clientes finais. Não inclua redes internas ou loopback por
conveniência: cada proxy deve ser efetivamente administrado e conhecido.

As verificações de permissão são reaplicadas no callback, antes do despacho e
após o processamento REST. Respostas do monitor recebem `private, no-store`,
`no-cache`, `must-revalidate` e `noindex, nofollow`. Exclua essas rotas de caches
reversos e CDNs: um cache que responda antes de executar o WordPress não pode ser
controlado por hooks PHP. IPs não substituem TLS e não protegem contra uma origem
autorizada comprometida ou usuários atrás do mesmo NAT.

Ao migrar, configure a integração e desative a cópia autônoma anterior. O plugin
não desativa outras extensões automaticamente. Duas cópias ativas podem aplicar
políticas distintas às rotas de compatibilidade. Com a integração desabilitada,
o WP Static Guard não assume as rotas históricas de outro plugin.

## Publicação e navegação

Os três cartões laterais formam um accordion exclusivo. Os botões internos são
abas com seleção, hover e navegação por setas, Home e End. O foco nativo é
preservado. As etiquetas são pequenas, com borda de 1 px e fundo transparente.

O cartão de conversão apresenta **Não publicado**, **Publicado**, **Atualizado**
ou **Desatualizado**. Alterações em conteúdos públicos, metadados, anexos,
taxonomias, menus, tema ou configurações da publicação invalidam o registro de
sincronização. Uma entrega completa registra a revisão capturada; se a origem
mudar durante o processamento, o resultado continua desatualizado. Alterar o
destino também invalida a sincronização registrada. Exportar somente o tema não
declara que as páginas foram atualizadas. Mudanças feitas diretamente em arquivos
do tema, fora dos hooks WordPress, exigem uma nova publicação para confirmação.

## Progresso e interrupções

Operações de publicação, configuração, diagnóstico, atualização, auditoria e
backup compartilham um registro de progresso restrito a administradores. Há um
lock por instalação e reserva por usuário, com recusa de concorrência e repetição
do mesmo pedido. São preservados os dez resultados mais recentes, sem credenciais.

O percentual representa etapas e unidades efetivamente executadas, sem estimar
o tempo restante. Páginas descobertas durante o rastreamento podem ampliar o
denominador. Etapas sem contagem incremental permanecem no último marco conhecido.
Quando disponível, o callback nativo do ZipArchive informa a compactação durante
o fechamento do pacote. A alternativa PclZip mantém o acompanhamento por etapas.
Não há animação que avance artificialmente o percentual.

A mensagem permanece visível, com cores de andamento, sucesso, alerta ou falha.
O título da aba apresenta o percentual. Reabrir o painel retoma a consulta de uma
execução ativa. Falhas não são apresentadas como 100% concluído. Perda de conexão
não é prova de interrupção no servidor: o painel tenta restabelecer a consulta;
consulte o registro antes de repetir uma operação. Processos interrompidos são
identificados quando o lock já não está ativo. O backup mantém a retenção de seis
cópias; o histórico de progresso não armazena os arquivos de backup.

Referência: [callback de progresso do ZipArchive](https://www.php.net/manual/en/ziparchive.registerprogresscallback.php).
