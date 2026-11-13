<?php
/** Modelo de apoio exclusivo para o fallback de temas clássicos sem 404. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
status_header( 404 );
nocache_headers();
get_header();
?>
<main class="gamb-static-error-page" tabindex="-1">
    <section class="gamb-static-error-panel" aria-labelledby="gamb-static-error-title">
        <p>Erro 404</p>
        <h1 id="gamb-static-error-title">Página não encontrada</h1>
        <p>O endereço informado não está disponível. Verifique o link ou retorne à página inicial.</p>
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Voltar à página inicial</a>
    </section>
</main>
<?php get_footer(); ?>
