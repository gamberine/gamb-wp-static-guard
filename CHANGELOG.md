# Registro de Versões (Changelog) — Gamb WP Static Guard

## [3.0.0] — 12/11/2026

### Reestruturação Completa em 2 Pilares (`1. STATIC` + `2. GUARD`), 100% em Português e Eliminação de Bloat
- **Separação Arquitetural Clara no Painel**:
  - **1. STATIC — Conversão e Publicação Estática**:
    1. *Painel & Publicação*: Seletor rápido com **Somente o Tema (Prioridade)**, **Apenas Alterações (Changes Only)** e **Site Completo (Full Site)**, resumo operacional e Registro de Atividades.
    2. *Escopo & URLs*: Reescrita de URLs (`Caminho Relativo`, `URL Absoluta`, `Uso Offline`), filtro de tipos de post e lista de exclusão de rotas sensíveis.
    3. *Destino de Implantação*: Entrega via `Arquivo ZIP`, `Diretório Local`, `GitHub Pages`, `AWS S3`, `FTP` e `SFTP`.
    4. *Automação Pós-Publicação*: `Auto Push` automático ao publicar/atualizar páginas ou produtos, botão `Single Push` na lateral do editor, `Webhook` e backup preventivo antes de exportar.
    5. *Performance & Busca Local*: Minificação de `HTML`, `CSS` e `JS` (preservando `jQuery` e `GTranslate`), `Defer JS` e busca estática local em JSON via `Fuse.js`.
  - **2. GUARD — Ocultação, Tokenização e Segurança**:
    6. *Tokenização & Ocultação (`/v/<token>`)**: Criptografia universal sem extensão para recursos de tema, imagens, plugins e `wp-includes`, com resolução recursiva de `url(...)` internos nos arquivos CSS e remoção de identificação de linguagem (`PHP` / `WordPress`).
    7. *Blindagem HTTP, Cache & API*: Isolamento de sessões autenticadas no `LiteSpeed Cache` (`DONOTCACHEPAGE`), bloqueio de raspagem anônima em `/wp-json/wp/v2/*`, desativação de `XML-RPC`, filtro de posts `teste-senha` e cabeçalhos `HSTS`, `CSP`, `X-Frame-Options`, `X-Content-Type-Options` e Cookies `HttpOnly`/`Secure`/`SameSite=Lax`.
    8. *Sanitização HTML & WCAG*: Fusão automática de atributos `class` duplicados, desduplicação de IDs repetidos (`#posts`, `#primary-menu-list`), injeção de `lang="pt-BR"` no `<html>`, reparo de `aria-labelledby` órfãos em modais e `rel="noopener noreferrer"` em links `target="_blank"`.
    9. *Backup (Estilo Duplicator) & Saúde*: Geração de backups completos (`database.sql` + `manifest.json` + temas e plugins) ou snapshots rápidos de banco SQL em pasta protegida, além de painel de Diagnóstico em português.
- **Dicas Práticas no Topo de Cada Aba**:
  - Adicionado banner contextual no topo de todas as 9 abas explicando o objetivo de cada seção e a configuração recomendada.
- **Eliminação Total de Arquivos e Integrações Sem Uso ("Só para Constar")**:
  - Removidos todos os arquivos mortos e integrações externas desnecessárias (`Static Studio`, `Tiiny.host`, `Algolia`, `ShortPixel`, `Beaver Builder`, `Divi`, `TikTok`, `Complianz`, `Events Calendar`, `NSG SEO`, catálogos em alemão/francês), reduzindo o pacote a um núcleo 100% funcional e enxuto.
- **Ações Automáticas Imediatas Pós-Instalação**:
  - Desativa plugins antigos conflitantes, grava o Perfil Padrão Seguro v3.0.0, instala o MU-Plugin Origin Shield, cria um Snapshot SQL inicial de backup automaticamente e limpa os caches (`LiteSpeed Cache` + `wp_cache_flush`).
- **Ajuste de CSS da Barra Lateral**:
  - Regra `.plugin-settings-container .sidebar` configurada com `max-width: 306px; padding: 25px; background: #fff; box-sizing: border-box; height: 100%; margin: 0;`.
