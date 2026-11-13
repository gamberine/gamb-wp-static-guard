=== Gamb WP Static Guard ===
Contributors: gamberine
Tags: static-site, export, security, local-search
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Exportação estática, publicação local, pesquisa no navegador e controles de exposição para WordPress.

== Descrição ==

O plugin gera páginas HTML e recursos públicos a partir de uma origem WordPress.
A entrega pode ser realizada em diretório local dedicado ou em arquivo ZIP.
O painel administrativo, a API, o namespace PHP e o índice de pesquisa possuem
identificadores próprios do Gamb WP Static Guard.

Endereços opacos não substituem autenticação ou autorização. A saída estática não
executa funções PHP da origem. A ferramenta não constitui certificação integral
de segurança ou acessibilidade.

== Instalação ==

Instale o pacote oficial da versão, confira seu SHA-256, ative o plugin e configure
o destino no menu Gamb Static Guard. Utilize Site completo para gerar páginas HTML.
Somente recursos do tema produz apenas CSS, JavaScript, fontes e imagens do tema.

== Operação ==

A atualização automática depende do agendador do WordPress. Os controles de remoção
de estilos e classes exigem validação prévia das dependências do tema. Cópias de
segurança não abrangem uploads nem a instalação completa; a restauração é manual.

Repositório, documentação e versões:
https://github.com/gamberine/gamb-wp-static-guard

== Registro de versões ==

= 4.2.0 =
Diagnóstico de instalação, inventário de dependências, 404 de apoio, canonical
da Home exportada e SRI dos recursos locais tokenizados finais. Bloqueio opcional
de publicação com dependências externas identificadas ou inspeção incompleta.

= 4.1.2 =
Validação do status e do contexto WordPress da captura da Home e do documento
de erro 404. Classes nativas de erro, pesquisa e arquivo removidas da publicação.

= 4.1.1 =
Preservação dos avisos de licença e direitos autorais das bibliotecas publicadas.

= 4.1.0 =
Barra lateral com caixas separadas para STATIC e GUARD, escopo independente,
primeira publicação completa obrigatória e retenção dos seis backups mais recentes.

= 4.0.0 =
Identidade independente, migração de configurações, API própria, validação de
HTML e restrição dos tipos de arquivo que podem ser resolvidos ou exportados.

= 3.0.1 =
Correções operacionais de renderização, tokenização, caminhos, publicação e pesquisa.

= 3.0.0 =
Base histórica da instalação preservada.
