<?php
/**
 * Homepage welcome hero.
 * Include this partial after the site header on index.php.
 */
?>
<section class="welcome-hero" aria-labelledby="welcome-hero-title">
    <div class="welcome-hero__image-wrap">
        <img
            class="welcome-hero__image"
            src="assets/images/welcome-hero.webp"
            alt="A creative art collage featuring a classical sculpture, paint brushes, a painter's palette, and architectural artwork"
            width="1672"
            height="570"
            fetchpriority="high"
            decoding="async"
        >
    </div>

    <div class="welcome-hero__content">
        <h1 class="welcome-hero__title" id="welcome-hero-title">
            Welcome to <span class="welcome-hero__brand">NSBM ArtCraft Platform</span>
        </h1>

        <blockquote class="welcome-hero__quote">
            <p>“Art is not a thing; it is a way.”</p>
            <cite>— Elbert Hubbard</cite>
        </blockquote>
    </div>
    <a class="exprore__button" href="#ps">Explore artwork</a>
</section>
