# Gamb WP Static Guard

![Identidade do produto](assets/gamb-wp-static-guard-logo.svg)

Extensão para WordPress destinada à geração de sites estáticos, à publicação em diretório local ou arquivo ZIP e à redução da exposição de informações técnicas na saída pública.

**Versão de referência:** 4.2.0. **Licença:** GPL-2.0-or-later. **Manutenção:** [gamberine](https://github.com/gamberine).

## Finalidade e limites

O componente **Static** consulta páginas públicas, reúne recursos e produz HTML, CSS, JavaScript, documentos e um índice de pesquisa local. O componente **Guard** reescreve caminhos de recursos, aplica controles configuráveis à saída e valida o HTML antes da entrega.

Endereços opacos não constituem controle de acesso. Arquivos publicados permanecem públicos. A ferramenta não substitui autenticação, autorização, atualização do servidor, análise de segurança nem auditoria integral de acessibilidade. O resultado estático não executa PHP, login, comentários ou operações de escrita da origem.

## Recursos disponíveis

- Exportação completa de conteúdos publicados e sem senha, arquivos, taxonomias e paginação descoberta.
- Entrega em pasta local dedicada ou pacote ZIP. O empacotamento estático utiliza a implementação disponível no WordPress quando a extensão ZIP não está instalada.
- Atualização programada após salvamento, publicação e despublicação, condicionada ao agendador da origem.
- Pesquisa textual no navegador com índice `search-index.json`, normalização de acentos e renderização por APIs DOM.
- Endereços de recursos no formato `/v/<token>`, com validação de caminho e lista de tipos permitidos.
- Validação que interrompe a entrega quando permanecem referências internas não resolvidas no HTML.
- Controles opcionais para estilos nativos, classes do corpo, versões de recursos e pré-carregamento especulativo.
- Diagnóstico inicial, inventário de dependências externas e requisito opcional de recursos locais antes da entrega.
- Página 404 de apoio para temas clássicos sem modelo próprio, canonical ausente da Home e SRI dos recursos tokenizados finais.
- Cópias administrativas do banco ou do banco com tema e extensões. O segundo pacote requer a extensão ZIP e não abrange uploads nem toda a instalação.

## Instalação

1. Obtenha o pacote da versão em [Releases](https://github.com/gamberine/gamb-wp-static-guard/releases).
2. Confira o arquivo `SHA256SUMS` correspondente ao pacote.
3. Em ambiente de homologação, instale o ZIP em **Plugins > Adicionar plugin > Enviar plugin**.
4. Ative o plugin e abra **Gamb Static Guard**. O diagnóstico inicial será executado; confira a aba **Diagnóstico da instalação**.
5. Configure destino, escopo, tipos de conteúdo e caminho público.
6. Execute uma publicação e valide navegação, documentos, pesquisa, responsividade e registros de execução.

O pacote contém uma única pasta `gamb-wp-static-guard`. Não inclui banco de dados, credenciais, uploads ou tema de qualquer instalação.

## Configuração operacional

Na primeira publicação, o servidor exige **Site Completo (Full Site)** e libera os demais escopos somente após a entrega bem-sucedida.

Para uma aplicação navegável, selecione **Site completo**. O modo **Somente recursos do tema** não produz páginas HTML. Atualizações individuais também reconstroem o site para manter listagens e pesquisa consistentes.

A pasta local deve existir, permitir gravação e ser dedicada à publicação. A raiz do WordPress, seus ancestrais e seus diretórios internos são rejeitados. O manifesto de publicação permite remover somente arquivos gerados anteriormente pelo próprio plugin.

O caminho público deve corresponder à localização da publicação: vazio para a raiz do domínio ou, por exemplo, `/portal` para uma subpasta. Alterações em menus, opções globais ou templates exigem nova publicação quando não houver um adaptador específico de agendamento.

A remoção de CSS nativo e de classes do corpo permanece desabilitada por padrão. Esses controles devem ser habilitados somente depois de verificar que o tema não depende deles. A minificação adicional também permanece desabilitada por padrão.

O painel apresenta caixas recolhíveis separadas para **STATIC/Conversão - HTML/ZIP** e **GUARD/Segurança - Blindado**. O escopo e o botão **Converter & Publicar Agora** permanecem em uma caixa independente.

O gerenciador mantém somente as **seis cópias de segurança mais recentes**, somando todos os tipos. A retenção ocorre após a conclusão de uma nova cópia, sem remover arquivos de terceiros.

A captura da raiz exige HTTP 200 e confirmação de que a origem está em contexto de página inicial, sem erro 404. A página de erro exige HTTP 404 real. Respostas incompatíveis interrompem a entrega e preservam a publicação anterior. Temas clássicos sem `404.php` recebem um modelo de apoio quando usam o fallback `index.php`; modelos próprios e de blocos são preservados.

O canonical é acrescentado somente quando ausente na Home exportada. O SRI dos estilos e scripts tokenizados é calculado sobre os arquivos finais. O diagnóstico distingue recursos externos de links de navegação; a opção de exigir recursos locais permanece desabilitada por padrão e pode impedir a entrega quando ativada. A análise não executa JavaScript nem baixa bibliotecas externas. Consulte a [matriz de portabilidade](docs/PORTABILITY.md) para identificar o que já é automático e o que depende do consumidor.

## Identificadores públicos

| Recurso | Identificador |
| --- | --- |
| Página administrativa | `admin.php?page=gamb-wp-static-guard` |
| API administrativa | `gamb-wp-static-guard/v1` |
| Namespace PHP | `GambWPStaticGuard` |
| Configuração principal | `gamb_wp_static_guard` |
| Domínio de tradução | `gamb-wp-static-guard` |
| Índice de pesquisa | `search-index.json` |

Os endpoints administrativos exigem `manage_options` e a autenticação do WordPress. O cliente do painel utiliza nonce de sessão. A versão 4 não desativa outras extensões nem gera uma cópia SQL automaticamente durante a ativação.

## Atualização a partir da versão 3

A mudança dos identificadores públicos motivou uma versão principal. A configuração anterior é identificada por impressão SHA-256 e migrada uma única vez, preservando valores `false`, `0`, `"0"`, vazios e seleções existentes. A opção anterior permanece no banco para recuperação. O perfil não é reaplicado a cada atualização.

Integrações PHP devem adotar o namespace e os nomes de opções atuais. Endpoints antigos não são mantidos como aliases. Consulte [Migração](docs/MIGRATION.md) antes de atualizar instalações com integrações próprias.

## Desenvolvimento e verificação

Requisitos declarados: WordPress 6.2 ou superior e PHP 7.4 ou superior. A validação local da versão 4 foi executada com WordPress 7.1.4 e PHP 8.3.26. A integração contínua verifica versões PHP adicionais; seu resultado deve ser consultado em [Actions](https://github.com/gamberine/gamb-wp-static-guard/actions).

```sh
php tests/run.php
python scripts/build.py
```

O script de compilação produz um ZIP instalável e seu resumo SHA-256 em `dist/`. A pasta de distribuição não é versionada. Consulte [Contribuição](CONTRIBUTING.md), [Segurança](SECURITY.md), [Registro de versões](CHANGELOG.md) e [Governança de versões](docs/RELEASES.md).

## Registro histórico

As etiquetas `v3.0.0` e `v3.0.1` documentam, respectivamente, a instalação preservada e as sete alterações operacionais incorporadas antes da separação do projeto. São registros históricos, não recomendações de instalação. O código específico de temas consumidores não integra este repositório.
