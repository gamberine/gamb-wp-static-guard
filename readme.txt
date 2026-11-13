=== Gamb WP Static Guard ===
Contributors: gamb
Tags: static site generator, security, token cloaking, backup, performance
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPLv2 or later

== Funcionamento ==

O plugin possui dois motores:

1. STATIC consulta as páginas públicas renderizadas pelo WordPress, grava o HTML,
   reúne os recursos utilizados e entrega a exportação em pasta local ou ZIP.
2. GUARD reescreve caminhos de recursos para /v/<token>, remove algumas assinaturas
   do WordPress, aplica filtros no HTML e gera regras para o servidor estático.
   Também pode aplicar a reescrita ao WordPress público enquanto o plugin está ativo.

O conteúdo continua sendo editado no WordPress. A exportação é uma cópia publicada
em HTML/CSS/JavaScript; não é um tema WordPress instalável nem executa templates PHP.
ACF e consultas ao banco são avaliados durante a geração das páginas na origem.
Depois da exportação, a navegação estática não precisa de PHP nem do banco de dados.

Os tokens ocultam os caminhos e nomes originais dos recursos. No site estático,
os arquivos são públicos e o navegador precisa recebê-los para exibi-los; os tokens
não são uma restrição de acesso a documentos privados nem criptografam seu conteúdo.

== Diferença entre os modos ==

* Somente o Tema: exporta CSS, JavaScript, fontes e imagens do tema. Não produz
  index.html. Use para obter recursos, não para publicar um site navegável.
* Site Completo: gera home, conteúdos públicos selecionados, arquivos de tipos de
  conteúdo, taxonomias, links públicos descobertos e paginação, além da página 404.
* Atualizar site e Single Push: nesta revisão fazem uma reconstrução completa para
  manter listagens, navegação, recursos e índice de busca sincronizados.

Rascunhos, conteúdos privados e conteúdos com senha não são exportados. Os tipos
de conteúdo e as exclusões configurados continuam controlando a descoberta inicial.
O rastreamento inteligente segue links públicos locais; não segue consultas de busca,
administração, diretórios internos do WordPress ou links para outros domínios.

== Configuração aplicada neste projeto ==

Origem WordPress: http://localhost/gaepe-minas/
Prévia estática: http://localhost/gaepe-minas/externo/
Modo: Site Completo
Entrega: Diretório Local no Servidor
Pasta: D:/laragon/www/gaepe-minas/externo
Tipo de URL: relativo ao domínio
Caminho relativo: /gaepe-minas/externo
Tipos selecionados: post, page, noticias
Minificação adicional: desativada
Defer adicional de JavaScript: desativado
Auto Push: ativado

Minificação e defer adicionais ficaram desativados para preservar a execução dos
scripts e a apresentação existentes. Bibliotecas já minificadas pelo tema continuam
sendo utilizadas normalmente. Nenhum conteúdo editorial ou campo ACF foi alterado.

== Como publicar e atualizar ==

1. Acesse o painel WordPress e abra o menu Gamb WP Static Guard.
2. Confira as configurações da seção STATIC e clique em Salvar Configurações.
3. Selecione Site Completo e clique em Converter & Publicar Agora.
4. Aguarde o sucesso no painel e confira o Registro de Atividades.
5. Acesse a prévia em http://localhost/gaepe-minas/externo/.

Ao salvar um conteúdo publicado dos tipos selecionados, o Auto Push agenda uma
exportação via WP-Cron. Despublicação e exclusão de conteúdo publicado também
agendam uma reconstrução. Eventos próximos são agrupados para evitar duplicação.
O WordPress precisa receber requisições ou ter seu cron executado pelo servidor;
acessar apenas o site estático não executa o WP-Cron da origem.

Alterações de tema, menus, opções ACF globais e conteúdo dependente da data devem
ser seguidas de uma publicação manual. Elas não são todas detectadas pelo save_post.

A pasta de destino deve existir, permitir gravação e ser dedicada à publicação.
O plugin rejeita a raiz do WordPress, pastas ancestrais e diretórios internos.
O manifesto .gamb-static-files.json permite remover somente arquivos anteriormente
gerados pelo plugin que ficaram obsoletos após uma reconstrução completa. Arquivos
não registrados no manifesto são preservados. Não edite a exportação manualmente:
na próxima publicação seus arquivos gerados serão sobrescritos.

== Estrutura da exportação ==

index.html                         Página inicial
noticias/.../index.html             Notícias e listagens
404.html                           Página de erro
v/<token> e v/<token>.<extensão>    Recursos referenciados pelo HTML e CSS
static/ui/                         Recursos do tema como cópia auxiliar
static/search.js                   Script de busca local
simply-static-search-index.json    Índice das páginas exportadas
.htaccess                          Regras de rotas, tipos MIME e bloqueios no Apache
_headers                           Cabeçalhos para serviços que aceitam esse formato
.gamb-static-files.json            Controle de arquivos da publicação local

Abra a prévia por HTTP com o Apache do Laragon. Abertura direta por file:// não foi
validada: a busca usa fetch e os tokens sem extensão dependem de regras do servidor.
O Apache 2.4 deve permitir .htaccess e utilizar mod_rewrite, mod_mime e mod_headers.
No Nginx ou em outra hospedagem, adapte rotas, tipos MIME e bloqueios ao servidor;
o arquivo _headers, sozinho, não implementa as regras de resolução dos tokens.

== Busca e funções dinâmicas ==

A busca local consulta o índice JSON no navegador e oferece até dez resultados,
com normalização de acentos. Atende input[name=s], input[type=search] e .search-field.
O layout atual da home não contém um campo de busca; o plugin não adiciona uma nova
interface visual. O motor é uma busca textual local, sem dependência do Fuse.js.

Login, comentários, formulários enviados ao WordPress, REST/AJAX e outras operações
PHP não passam a funcionar offline apenas pela exportação. Links e serviços externos
continuam dependendo dos respectivos destinos. Nenhum serviço externo foi implantado.

== Entregas disponíveis e backups ==

Nesta versão, as entregas implementadas são pasta local e ZIP. GitHub, AWS S3, FTP
e SFTP aparecem desabilitados; não existe publicação remota implementada neste motor.
Um webhook é uma notificação de conclusão e não equivale à implantação do site.

Para publicar em outro domínio na raiz, configure o caminho relativo vazio antes
de gerar novamente. Para publicar numa subpasta, informe o caminho público completo
dessa subpasta. Copie o conteúdo da exportação, inclusive .htaccess, ao servidor Apache.
Não copie o WordPress inteiro. Esta entrega foi validada somente no ambiente local.

A ferramenta de backup é separada da exportação estática. O backup de banco contém
o SQL; o modo completo acrescenta o tema ativo, eventual tema pai e plugins. Esse
modo não inclui os uploads nem todos os arquivos da instalação e não possui um
instalador automático equivalente ao Duplicator. Restauração de backup não foi
executada nesta implementação.

== Revisão 3.0.1 ==

Corrigidos padrões regex que apagavam HTML e JavaScript, resposta binária contaminada
por saída anterior, caminhos em subpastas, permalinks /index.php/, registro de tokens
recebidos da origem, dependências CSS, paginação, página 404 e busca local. Exportações
concorrentes são bloqueadas; falhas HTTP e de entrega deixam de ser anunciadas como
sucesso. Caminhos de publicação são verificados antes da exportação. O perfil GUARD
existente não é reaplicado automaticamente por esta revisão.

As cópias anteriores, configurações, scripts e evidências desta implementação estão
em .codex-artifacts/static-guard-20261113/, fora da exportação pública e com acesso
HTTP negado. Consulte IMPLEMENTACAO-WP-STATIC-GUARD.md na raiz do projeto.
