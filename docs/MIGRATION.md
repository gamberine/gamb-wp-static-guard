# Migração para a versão 4

## Procedimento

1. Preserve o pacote em uso, a configuração e uma cópia de segurança operacional da instalação.
2. Homologue a versão 4 e verifique as integrações PHP e os clientes da API.
3. Instale o pacote sem remover o banco ou os conteúdos da origem.
4. Confira destino, caminho público, tipos selecionados e atualização automática.
5. Gere uma exportação completa para substituir o índice antigo e os arquivos gerados obsoletos registrados no manifesto.
6. Valide recursos, documentos, pesquisa, formulários, navegação e acessibilidade.

## Persistência

A configuração anterior é reconhecida por uma impressão SHA-256 de seu identificador. A descoberta não implica acesso a serviços externos. Somente opções implementadas são transferidas à configuração atual. Valores de opções suportadas não são normalizados por veracidade: `false`, zero inteiro e `"0"` permanecem distintos.

A opção anterior é preservada para recuperação. Os registros de atividade da nova linha começam separados; evidências anteriores permanecem na origem preservada. Não são migrados banco de conteúdo, credenciais de serviços não implementados ou cadastros editoriais para arquivos do repositório.

## Integrações

Utilize `GambWPStaticGuard\Options` e `GambWPStaticGuard\Static_Engine`. O agendamento continua associado a `gamb_static_guard_auto_export_cron`. As chaves `single_enabled`, `auto_export`, `auto_export_delay` e `webhook_url` substituem os identificadores anteriores da mesma finalidade.

A API utiliza `gamb-wp-static-guard/v1`. Chamadas antigas não são redirecionadas automaticamente. A publicação programada depende da execução do agendador na origem.

## Recuperação

Se a homologação falhar, restaure o pacote anterior e as integrações correspondentes. A configuração anterior foi mantida no banco para esse fim. Preserve eventuais alterações editoriais posteriores e não restaure um banco completo indiscriminadamente. Refaça a exportação após concluir a recuperação.
