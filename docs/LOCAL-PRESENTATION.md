# Endereço local e escolha da apresentação

Em **Destino e endereços**, **Usar endereço local do projeto** preenche protocolo, host, caminho e formato a partir da URL principal do WordPress. A ação apenas preenche os campos; é preciso salvar. Não edita `home`, `siteurl`, hosts, DNS, Apache, `.htaccess` da origem ou outros projetos.

**O que abrir na URL principal do projeto** oferece:

- **WordPress:** comportamento padrão em instalações novas. A origem dinâmica atende a URL principal. Os arquivos exportados permanecem na pasta configurada.
- **Publicação estática na URL principal:** o WordPress entrega os bytes dos arquivos registrados no manifesto local. Exige destino igual à URL principal da instalação, entrega local e endereços relativos ao domínio ou absolutos. A administração, API, login, cron, preview autorizado do editor e captura assinada da exportação continuam na origem.

O segundo modo não converte a instalação em um host sem PHP: a requisição passa pela inicialização do WordPress para entregar os arquivos. Para um servidor totalmente estático, use o pacote/diretório em um host separado. Nenhuma alteração global de Laragon é necessária para esta apresentação local.

Após trocar URL ou ambiente, execute uma publicação completa. O identificador privado `.gamb-static-publication.json` associa o manifesto ao destino/ambiente; sem uma entrega correspondente o modo estático informa indisponibilidade temporária (503), preservando o admin. Isso impede apresentar silenciosamente uma exportação feita para outro endereço. Alternar a apresentação de uma entrega já válida não exige nova exportação e não altera seus arquivos.

As respostas de arquivos usam os tipos MIME e os headers de publicação já definidos pelo plugin, respeitando preview/homologação e HTTPS. Não há reprocessamento dos bytes de CSS/JS/HTML, preservando a integridade SRI. Somente GET/HEAD são aceitos; caminhos ocultos, travessia, links simbólicos, arquivos não registrados, backups e código de servidor não são entregues por este fluxo. Rotas ausentes usam o 404 exportado, mantendo status 404.

Para reverter, selecione **WordPress** e salve. A pasta da publicação e o conteúdo editorial não são removidos.
