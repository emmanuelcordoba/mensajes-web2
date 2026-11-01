{{--
    La landing pública, en la raíz del sitio. Es la misma del sistema viejo.

    ⚠️ **ES UNA EXPORTACIÓN, NO UNA PÁGINA ESCRITA A MANO.** La hizo Astro y lo que quedó es
    su HTML ya construido —de ahí los `data-astro-cid-*` y el CSS con hash en el nombre—. No
    tiene una sola directiva de Blade: se sirve con `Route::view`, sin controlador y sin
    datos. Si alguna vez hay que rehacerla, se rehace en su proyecto y se vuelve a exportar;
    editar estas 1.037 líneas a mano es trabajo que se pierde en la exportación siguiente.

    ⚠️ **VA AISLADA A PROPÓSITO**, igual que en producción: no pasa por Inertia, no monta
    React y no usa `@vite`. Trae su propio `<!DOCTYPE>`, su CSS en `/landing/` y sus fuentes.
    Es la primera página que ve alguien que no es usuario del sistema, y no tiene por qué
    cargar la aplicación entera para mostrar un teléfono y un enlace a Play Store.

    ⚠️ **NO TIENE NINGÚN ENLACE A LA APLICACIÓN**, y no es un olvido de la migración: se
    comprobó. Son anclas a sus propias secciones, WhatsApp y Play Store. Por eso el porteo no
    tuvo que recablear ninguna ruta.

    Cuatro cosas que SÍ se cambiaron respecto de producción:

    - **El favicon**, que allá apunta a `..public/landing/images/pagina/isotipoZenithBlanco.png`
      —una ruta rota, y además un archivo que no existe en el repositorio—. Ahora apunta a
      `favicon.svg`, que está en la misma carpeta, es la marca y es lo que la etiqueta ya
      declaraba con `type="image/svg+xml"`.
    - **`lang="en"`**, que quedó del tema de Astro. La página está en español.
    - **El script del `typing-container`**, que se sacó porque buscaba un elemento que
      no existe y tiraba 28 errores por visita. El porqué está donde estaba el código.
    - **Los scripts externos**, que eran cuatro y ahora son cero. La página cargaba
      ALPINE DOS VECES —2.x desde un CDN y 3.x desde otro—, que es cargar dos versiones
      distintas del mismo framework en la misma página; e ionicons dos veces más, para
      cero `<ion-icon>` en todo el archivo. Alpine quedó servido desde `/landing/`, con
      la versión fija en el nombre del archivo. Es la misma decisión que está escrita en
      `cupon.blade.php`: lo que la página necesita para no salir desarmada no se pide
      prestado a un CDN. Acá pesa más todavía, porque ésta es la primera página que ve
      alguien que no es usuario del sistema, y Alpine es lo que abre el menú en el
      teléfono.

    Las tres son reparaciones de algo roto, no retoques: lo que se rehace en el proyecto
    de Astro es el diseño, y eso sigue valiendo.

    De los 98 archivos de `public/landing` se trajeron los 64 que el HTML y el CSS
    referencian de verdad. Los otros 34 —3 MB de logos de Netflix y Airbnb, avatares y PNG
    sueltos— son de la plantilla y no los pide nadie.
--}}
<!DOCTYPE html>
<html lang="es" data-astro-cid-sckkx6r4>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width">
  <link rel="icon" type="image/svg+xml" href="/landing/images/pagina/favicon.svg">
  <meta name="description" content="">
  <title>Mensajes - Cadeteria</title>
  <link as="font" crossorigin rel="preload"
        href="https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqDyx4fFg.ttf" type="font/ttf">
  <link as="font" crossorigin rel="preload"
        href="https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqD-R4fFg.ttf" type="font/ttf">
  <link as="font" crossorigin rel="preload"
        href="https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqDFRkfFg.ttf" type="font/ttf">
  <link as="font" crossorigin rel="preload"
        href="https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqDLBkfFg.ttf" type="font/ttf">
  <style>@font-face {
      font-weight: 400;
      font-style: normal;
      font-family: Urbanist;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqDyx4fFg.ttf)
    }</style>
  <style>@font-face {
      font-weight: 500;
      font-style: normal;
      font-family: Urbanist;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqD-R4fFg.ttf)
    }</style>
  <style>@font-face {
      font-weight: 600;
      font-style: normal;
      font-family: Urbanist;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqDFRkfFg.ttf)
    }</style>
  <style>@font-face {
      font-weight: 700;
      font-style: normal;
      font-family: Urbanist;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/urbanist/v15/L0xjDF02iFML4hGCyOCpRdycFsGxSrqDLBkfFg.ttf)
    }</style>
  <style>html {
      font-family: Urbanist, _font_fallback_1733787169526, sans-serif;
    }

    @font-face {
      font-family: _font_fallback_1733787169526;
      size-adjust: 100.98%;
      src: local('Arial');
      ascent-override: 94.08%;
      descent-override: 24.76%;
      line-gap-override: 0.00%;
    }</style>
  <link rel="stylesheet" href="/landing/index.dUoeYG_d.css">
  <script type="module">const n = document.querySelectorAll("input[name='slider']");
    let s = Array.from(n).findIndex(e => e.checked);

    function B() {
      s = (s + 1) % n.length, n[s].checked = !0
    }

    function x() {
      s = (s - 1 + n.length) % n.length, n[s].checked = !0
    }

    document.getElementById("nextBtn").addEventListener("click", B);
    document.getElementById("prevBtn").addEventListener("click", x);
    const u = document.querySelectorAll(".acc-item");
    u.forEach(e => {
      e.addEventListener("click", () => {
        e.classList.toggle("open"), e.classList.toggle("close"), e.classList.contains("open") && q(e)
      })
    });
    const q = e => {
      u.forEach(t => {
        t !== e && t.classList.contains("open") && (t.classList.toggle("open"), t.classList.toggle("close"))
      })
    }, r = document.querySelector(".slider");

    function w(e) {
      const t = document.querySelectorAll(".item");
      e.target.matches(".next") && r.append(t[0]), e.target.matches(".prev") && r.prepend(t[t.length - 1])
    }

    document.addEventListener("click", w, !1);
    /*
     * Acá iba un script que escribía «Tu Paquete en Manos Seguras» letra por letra
     * dentro de #typing-container. Ese elemento NO EXISTE: la cadena aparecía una
     * sola vez en todo el archivo, en el `getElementById` que la buscaba. Resultado,
     * desde que la página se publicó: el título nunca se mostró y cada visita tiraba
     * 28 errores en consola, uno por cada letra de la cadena.
     *
     * Se sacó en lugar de agregarle el elemento, y la razón es que el titular
     * animado ya no es parte del diseño: tampoco está la clase `.agency`, que es la
     * que tiene el efecto hecho en CSS —`animation: typing 2s steps(60)`, con el
     * cursor en un `border-right`— y que sigue en la hoja sin que nadie la use. El
     * hero tiene hoy su titular estático. Ponerle el elemento sería inventar un
     * segundo título que nadie diseñó.
     *
     * Si alguna vez se quiere ese título de vuelta, es una decisión de diseño y va
     * en el proyecto de Astro, no acá.
     */
    document.getElementById("whatsappForm").addEventListener("submit", function (e) {
      e.preventDefault();
      var t = document.getElementById("desde").value, v = document.getElementById("hasta").value,
        p = document.getElementById("company").value, y = document.getElementById("email").value,
        h = document.getElementById("phone-number").value, L = document.getElementById("message").value,
        E = `Hola Mensajes Cadetería, quisiera hacer un envío desde ${t} hasta ${v}. A nombre de ${p}. Email: ${y}. Teléfono: ${h}. Indicaciones: ${L}`,
        f = "3814739347", k = `https://wa.me/${f}?text=${encodeURIComponent(E)}`;
      window.open(k, "_blank")
    });
    /*
     * Acá iba una SEGUNDA implementación del menú móvil, en JS a secas, que buscaba
     * #navlinks, #hamburger y #navLayer. Ninguno de los tres existe en este marcado:
     * el menú lo maneja Alpine, con `x-data`, `@click` y `:class`, unas líneas más
     * arriba en el <header>. Era código de otra versión de la plantilla, y tiraba un
     * error en cada carga porque `l.querySelector` corría sobre null.
     */
  </script>
</head>
<body class="bg-white" data-astro-cid-sckkx6r4>
<header data-astro-cid-qgrrgaau>
  <div class="w-full absolute z-10 border-b-2 border-primary"
       style="background-color: #f7f7f7; position: fixed;z-index: 999;" data-astro-cid-qgrrgaau>
    <div x-data="{ open: false }"
         class="flex flex-col max-w-screen-xl px-4 mx-auto md:items-center md:justify-between md:flex-row md:px-6 lg:px-8"
         data-astro-cid-qgrrgaau>
      <div class="p-4 flex flex-row items-center justify-between" data-astro-cid-qgrrgaau><a href="#"
                                                                                             class="text-lg font-semibold tracking-widest text-gray-900 uppercase rounded-lg dark-mode:text-white focus:outline-none focus:shadow-outline"
                                                                                             data-astro-cid-qgrrgaau>
          <img class="w-40" src="/landing/images/pagina/logo.jpeg" alt="" data-astro-cid-qgrrgaau> </a>
        <button class="md:hidden rounded-lg focus:outline-none focus:shadow-outline" @click="open = !open"
                data-astro-cid-qgrrgaau>
          <svg fill="currentColor" viewBox="0 0 20 20" class="w-6 h-6" data-astro-cid-qgrrgaau>
            <path x-show="!open" fill-rule="evenodd"
                  d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM9 15a1 1 0 011-1h6a1 1 0 110 2h-6a1 1 0 01-1-1z"
                  clip-rule="evenodd" data-astro-cid-qgrrgaau></path>
            <path x-show="open" fill-rule="evenodd"
                  d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                  clip-rule="evenodd" data-astro-cid-qgrrgaau></path>
          </svg>
        </button>
      </div>
      <nav :class="{'flex': open, 'hidden': !open}"
           class="flex-col flex-grow pb-4 md:pb-0 hidden md:flex md:justify-center md:flex-row" data-astro-cid-qgrrgaau>
        <ul class="flex flex-col gap-6 tracking-wide lg:flex-row lg:gap-0 lg:text-sm" data-astro-cid-qgrrgaau>
          <div class="w-full text-gray-600 dark:text-gray-200 lg:w-auto lg:pt-0" data-astro-cid-qgrrgaau>
            <ul class="flex flex-col gap-6 tracking-wide lg:flex-row lg:gap-0 lg:text-sm" data-astro-cid-qgrrgaau>
              <li data-astro-cid-qgrrgaau><a href="/#Nosotros"
                                             class="text-l hover:text-black block transition dark:hover:text-black md:px-4 lg:hidden"
                                             style="color: #fa001a;" data-astro-cid-qgrrgaau> <span class="text-lg"
                                                                                                    data-astro-cid-qgrrgaau>Nosotros</span>
                </a></li>
              <li data-astro-cid-qgrrgaau><a href="/#Servicios"
                                             class="text-l hover:text-black block transition dark:hover:text-black md:px-4 lg:hidden"
                                             style="color: #fa001a;" data-astro-cid-qgrrgaau> <span class="text-lg"
                                                                                                    data-astro-cid-qgrrgaau>Servicios</span>
                </a></li>
            </ul>
          </div>
        </ul>
        <div class="mt-10 flex flex-wrap md:hidden justify-start gap-y-4 gap-x-6 boton-header" data-astro-cid-qgrrgaau>
          <a
            href="https://api.whatsapp.com/send?phone=+5493814739347&text=Hola Mensajes Cadeteria, quisiera realizar un envio"
            target="_blank"
            class="relative flex h-12 w-full items-center justify-center px-6 before:absolute before:inset-0 before:rounded-full before:bg-white before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 sm:w-max"
            data-astro-cid-qgrrgaau> <span class="relative text-base font-semibold" style="color: #fa001a;"
                                           data-astro-cid-qgrrgaau>Hacer un pedido ahora</span> </a> <a
            href="https://play.google.com/store/apps/details?id=com.nuggosoft.mensajesapp&hl=es_AR" target="_blank"
            class="relative flex h-12 w-full items-center justify-center px-8 py-6 before:absolute before:inset-0 before:rounded-full before:bg-[#fa001a] before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 sm:w-max"
            data-astro-cid-qgrrgaau> <span class="relative text-base font-semibold text-white" data-astro-cid-qgrrgaau>Descargar app</span>
          </a></div>
      </nav>
      <div class="mt-12 lg:mt-0 hidden lg:block" data-astro-cid-qgrrgaau><a
          href="https://play.google.com/store/apps/details?id=com.nuggosoft.mensajesapp&hl=es_AR" target="_blank"
          class="text-white relative flex px-6 py-4 w-full items-center justify-center before:absolute before:inset-0 before:rounded-full before:bg-[#fa001a;] before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 sm:w-max"
          data-astro-cid-qgrrgaau> <span class="relative text-sm font-semibold text-white" data-astro-cid-qgrrgaau>
Descargar App</span> </a></div>
    </div>
  </div>
</header>
<main class="space-y-40 mb-40">  <!-- <div class="relative HeaderPri" id="home">
  <div
    aria-hidden="true"
    class="absolute inset-0 grid grid-cols-2 -space-x-52 opacity-40 dark:opacity-10 bg-cover"
  >
  </div>
</div> -->
  <div class="relative pt-20 ml-auto hero" style="  background-color: #fa001a;;
    opacity: 0.90;" data-astro-cid-nlow4r3u>
    <div class="max-w-7xl mx-auto px-6 md:px-12 xl:px-6">
      <div class="header grid lg:grid-cols-2 place-items-center pb-4 md:pt-4- md:pb-8" data-astro-cid-nlow4r3u>
        <div class="py-6 md:order-1 hidden md:block" data-astro-cid-nlow4r3u><img class="fadeInUp duration-1000"
                                                                                  src="/landing/images/pagina/hero.png  "
                                                                                  alt="" data-astro-cid-nlow4r3u></div>
        <div class="" data-astro-cid-nlow4r3u>
          <div class="contenedor mb-6 contenedor-titulo" data-astro-cid-nlow4r3u><h1
              class="text-7xl text-start font-bold" data-astro-cid-nlow4r3u>
              ¡Tus envíos, nuestra prioridad!
            </h1></div>
          <p class="subtitulo text-white text-start text-1xl" data-astro-cid-nlow4r3u>
            Ofrecemos servicios de mensajería y cadetería eficientes y confiables
          </p>
          <div class="mt-10 flex flex-wrap justify-start gap-y-4 gap-x-6 boton-header" data-astro-cid-nlow4r3u><a
              href="https://api.whatsapp.com/send?phone=+5493814739347&text=Hola Mensajes Cadeteria, quisiera realizar un envio"
              target="_blank"
              class="relative flex h-12 w-full items-center justify-center px-6 before:absolute before:inset-0 before:rounded-full before:bg-white before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 sm:w-max"
              data-astro-cid-nlow4r3u> <span class="relative text-base font-semibold" style="color: #fa001a;"
                                             data-astro-cid-nlow4r3u>Hacer un pedido ahora</span> </a> <a
              href="https://play.google.com/store/apps/details?id=com.nuggosoft.mensajesapp&hl=es_AR" target="_blank"
              class="relative flex h-12 w-full items-center justify-center px-8 before:absolute before:inset-0 before:rounded-full before:border-transparent before:bg-transparent border-white before:bg-gradient-to-b before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 before:border-white before:border-2 sm:w-max"
              data-astro-cid-nlow4r3u> <span class="relative text-base font-semibold text-white"
                                             data-astro-cid-nlow4r3u>Descargar app</span> </a> <!-- <a
                      href="#"
                      class="relative flex h-11 w-full items-center justify-center px-6 before:absolute before:inset-0 before:rounded-full before:border before:border-transparent before:bg-primary/10 before:bg-gradient-to-b before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 dark:before:border-gray-700 dark:before:bg-gray-800 sm:w-max"
                    >
                      <span
                        class="relative text-base font-semibold text-primary dark:text-white"
                        >Learn more</span
                      >
                    </a> --> </div>
        </div>
      </div>
    </div>
  </div>
  <div id="HorinzontalInfiniteBrands" class="HorinzontalInfiniteBrands" data-astro-cid-v26ixt2h>
    <div class="max-w-7xl mx-auto px-6 md:px-12 xl:px-6">
      <div class="relative font-inter antialiased" data-astro-cid-v26ixt2h>
        <main class="relative flex flex-col justify-center bg-slate-900 overflow-hidden containerSectionBrands"
              data-astro-cid-v26ixt2h>
          <div class="w-full max-w-5xl mx-auto px-4 md:px-6" data-astro-cid-v26ixt2h>
            <div class="text-center" data-astro-cid-v26ixt2h> <!-- Logo Carousel animation  -->
              <div x-data="{}" x-init="$nextTick(() => {
                            let ul = $refs.logos;
                            ul.insertAdjacentHTML('afterend', ul.outerHTML);
                            ul.nextSibling.setAttribute('aria-hidden', 'true');
                        })"
                   class="w-full inline-flex flex-nowrap overflow-hidden [mask-image:_linear-gradient(to_right,transparent_0,_black_128px,_black_calc(100%-128px),transparent_100%)]"
                   data-astro-cid-v26ixt2h>
                <ul x-ref="logos"
                    class="flex items-center justify-center md:justify-start [&_li]:mx-8 [&_img]:max-w-none animate-infiniteScroll"
                    data-astro-cid-v26ixt2h>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/bercovich.jpeg" alt="Bercovich"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/casaGraf.jpeg" alt="Casa Graf"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/elAmigo.jpeg" alt="el Amigo Corralon"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/EstiloCanino.jpeg" alt="Estilo Canino"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/fdelpueblo.jpeg"
                                                   alt="Farmacia del Pueblo" data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/Lidherma.jpeg" alt="Lidherma"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/marderplak.jpeg" alt="Marderplack"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/stud.jpeg" alt="stud"
                                                   data-astro-cid-v26ixt2h></li>
                  <li data-astro-cid-v26ixt2h><img src="/landing/images/brands/villarMotod.jpeg" alt="Villar Motos"
                                                   data-astro-cid-v26ixt2h></li>
                </ul>
              </div> <!-- End: Logo Carousel animation  --> </div>
          </div>
        </main>
      </div>
    </div>
  </div>
  {{-- Acá se cargaba ionicons, dos veces, para cero <ion-icon> en la página. --}}
  <div id="Nosotros" class="valores" data-astro-cid-vnivfuh2>
    <div class="max-w-7xl mx-auto px-6 md:px-12 xl:px-6">
      <div class="relative my-10 pt-10 rounded-xl burbujas" style=";height: 65vh;" data-astro-cid-vnivfuh2><h3
          class="text-center pt-4 text-4xl leading-8 font-extrabold tracking-tight text-black sm:text-4xl sm:leading-10"
          data-astro-cid-vnivfuh2>
          Servicios
        </h3>
        <div class="my-10 space-y-1" data-astro-cid-vnivfuh2></div>
        <div data-astro-cid-vnivfuh2>
          <div
            class="space-y-6 justify-between text-gray-600 md:flex flex-row-reverse md:gap-6 md:space-y-0 lg:gap-12 lg:items-center"
            data-astro-cid-vnivfuh2></div>
          <div class="relative font-inter antialiased" data-astro-cid-vnivfuh2>
            <div class="w-full max-w-5xl mx-auto py-6 servDesktop" data-astro-cid-vnivfuh2>
              <!-- Botones izquierda y derecha -->
              <button id="prevBtn"
                      class="absolute top-1/2 transform -translate-y-1/2 bg-gray-200 hover:bg-gray-300 rounded-full p-2 shadow-lg z-50"
                      style="background: #fa001a; left: 15rem; width: 55px; height: 55px; color: white;"
                      data-astro-cid-vnivfuh2>
                &#10094;
              </button>
              <button id="nextBtn"
                      class="absolute top-1/2 transform -translate-y-1/2 bg-gray-200 hover:bg-gray-300 rounded-full p-2 shadow-lg z-50"
                      style="    background: #fa001a; right:15rem;width: 55px; color:white;
    height: 55px;" data-astro-cid-vnivfuh2>
                &#10095;
              </button> <!-- Card slider  -->
              <section class="px-12" data-astro-cid-vnivfuh2>
                <div class="max-w-lg mx-auto relative" data-astro-cid-vnivfuh2><input id="article-01" type="radio"
                                                                                      name="slider"
                                                                                      class="sr-only peer/01" checked
                                                                                      data-astro-cid-vnivfuh2> <input
                    id="article-02" type="radio" name="slider" class="sr-only peer/02" data-astro-cid-vnivfuh2> <input
                    id="article-03" type="radio" name="slider" class="sr-only peer/03" data-astro-cid-vnivfuh2> <input
                    id="article-04" type="radio" name="slider" class="sr-only peer/04" data-astro-cid-vnivfuh2> <input
                    id="article-05" type="radio" name="slider" class="sr-only peer/05" data-astro-cid-vnivfuh2>
                  <div class="absolute inset-0 scale-[67.5%] z-20 transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)]
                          peer-focus-visible/01:[&_article]:ring
                          peer-focus-visible/01:[&_article]:ring-indigo-300
                          peer-checked/01:relative
                          peer-checked/01:z-50
                          peer-checked/01:translate-x-0
                          peer-checked/01:scale-100
                          peer-checked/01:[&>label]:pointer-events-none
                          peer-checked/02:-translate-x-20
                          peer-checked/02:scale-[83.75%]
                          peer-checked/02:z-40
                          peer-checked/03:-translate-x-40
                          peer-checked/03:z-30
                          peer-checked/04:-translate-x-40
                          peer-checked/04:opacity-0
                          peer-checked/05:-translate-x-40" data-astro-cid-vnivfuh2><label class="absolute inset-0"
                                                                                          for="article-01"
                                                                                          data-astro-cid-vnivfuh2><span
                        class="sr-only" data-astro-cid-vnivfuh2>Servicio coorporativo de entregas</span></label>
                    <article class="h-[350px] w-[400px] p-6 rounded-lg bg-white shadow-xl"
                             style="border-right: 1rem solid black; border-left: 1px solid black; border-block: 1px solid black"
                             data-astro-cid-vnivfuh2>
                      <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                           stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                           class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                           data-astro-cid-vnivfuh2>
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                        <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                        <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                        <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                        <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                        <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                        <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                      </svg>
                      <header class="mb-2" data-astro-cid-vnivfuh2><h1 class="text-xl font-bold text-black"
                                                                       data-astro-cid-vnivfuh2>
                          Servicio coorporativo de entregas
                        </h1></header>
                      <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-8 mt-6"
                           data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                          Ofrecemos soluciones logísticas confiables y ágiles para
                          empresas que necesitan un manejo profesional de envíos.
                          Nos ocupamos de la entrega rápida de documentos y
                          productos con la garantía, puntualidad y seguridad en
                          cada envío, ajustándonos a las necesidades específicas
                          de tu empresa.
                        </p></div>
                    </article>
                  </div>
                  <div class="absolute inset-0 scale-[67.5%] z-20 transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)]
                          peer-focus-visible/02:[&_article]:ring
                          peer-focus-visible/02:[&_article]:ring-indigo-300
                          peer-checked/01:translate-x-20
                          peer-checked/01:scale-[83.75%]
                          peer-checked/01:z-40
                          peer-checked/02:relative
                          peer-checked/02:z-50
                          peer-checked/02:translate-x-0
                          peer-checked/02:scale-100
                          peer-checked/02:[&>label]:pointer-events-none
                          peer-checked/03:-translate-x-20
                          peer-checked/03:scale-[83.75%]
                          peer-checked/03:z-40
                          peer-checked/04:-translate-x-40
                          peer-checked/04:z-30
                          peer-checked/05:-translate-x-40
                          peer-checked/05:opacity-0" data-astro-cid-vnivfuh2><label class="absolute inset-0"
                                                                                    for="article-02"
                                                                                    data-astro-cid-vnivfuh2><span
                        class="sr-only" data-astro-cid-vnivfuh2>Servicio Delivery por hora:
</span></label>
                    <article class="h-[350px] w-[400px] p-6 rounded-lg bg-white shadow-xl"
                             style="border-right: 1rem solid black; border-left: 1px solid black; border-block: 1px solid black"
                             data-astro-cid-vnivfuh2>
                      <header class="mb-2" data-astro-cid-vnivfuh2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                             stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                             data-astro-cid-vnivfuh2>
                          <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                          <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                          <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                          <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                          <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                        </svg>
                        <h1 class="text-xl font-bold text-black" data-astro-cid-vnivfuh2>
                          Servicio Delivery por hora:
                        </h1></header>
                      <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-8"
                           data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                          Es perfecto para empresas o particulares que requieren
                          entregas continuas o múltiples en un mismo período, sin
                          tener que preocuparse por los costos por envio. Con este
                          servicio, te cobramos por el tiempo de uso del
                          mensajero, no por cada entrega individual.
                        </p></div>
                    </article>
                  </div>
                  <div class="absolute inset-0 scale-[67.5%] z-20 transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)]
                          peer-focus-visible/03:[&_article]:ring
                          peer-focus-visible/03:[&_article]:ring-indigo-300
                          peer-checked/01:translate-x-40
                          peer-checked/01:z-30
                          peer-checked/02:translate-x-20
                          peer-checked/02:scale-[83.75%]
                          peer-checked/02:z-40
                          peer-checked/03:relative
                          peer-checked/03:z-50
                          peer-checked/03:translate-x-0
                          peer-checked/03:scale-100
                          peer-checked/03:[&>label]:pointer-events-none
                          peer-checked/04:-translate-x-20
                          peer-checked/04:scale-[83.75%]
                          peer-checked/04:z-40
                          peer-checked/05:-translate-x-40
                          peer-checked/05:z-30" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-03"
                                                                               data-astro-cid-vnivfuh2><span
                        class="sr-only" data-astro-cid-vnivfuh2>Entregas inmediatas:</span></label>
                    <article class="h-[350px] w-[400px] p-6 rounded-lg bg-white shadow-xl"
                             style="border-right: 1rem solid black; border-left: 1px solid black; border-block: 1px solid black"
                             data-astro-cid-vnivfuh2>
                      <header class="mb-2" data-astro-cid-vnivfuh2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                             stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                             data-astro-cid-vnivfuh2>
                          <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                          <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                          <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                          <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                          <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                        </svg>
                        <h1 class="text-xl font-bold text-black" data-astro-cid-vnivfuh2>
                          Entregas inmediatas:
                        </h1></header>
                      <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                           data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                          está diseñado para satisfacer las necesidades de los
                          clientes que requieren rapidez y precisión. Sin
                          complicaciones ni demoras, tu paquete o documento
                          llegará a su destino en minutos.
                        </p></div>
                    </article>
                  </div>
                  <div class="absolute inset-0 scale-[67.5%] z-20 transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)]
                          peer-focus-visible/04:[&_article]:ring
                          peer-focus-visible/04:[&_article]:ring-indigo-300

                          peer-checked/01:translate-x-40
                          peer-checked/01:opacity-0

                          peer-checked/02:translate-x-40
                          peer-checked/02:z-30

                          peer-checked/03:translate-x-20
                          peer-checked/03:scale-[83.75%]
                          peer-checked/03:z-40

                          peer-checked/04:relative
                          peer-checked/04:z-50
                          peer-checked/04:translate-x-0
                          peer-checked/04:scale-100
                          peer-checked/04:[&>label]:pointer-events-none

                          peer-checked/05:-translate-x-20
                          peer-checked/05:scale-[83.75%]
                          peer-checked/05:z-40" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-04"
                                                                               data-astro-cid-vnivfuh2><span
                        class="sr-only" data-astro-cid-vnivfuh2>Tramites personales:</span></label>
                    <article class="h-[350px] w-[400px] p-6 rounded-lg bg-white shadow-xl"
                             style="border-right: 1rem solid black; border-left: 1px solid black; border-block: 1px solid black"
                             data-astro-cid-vnivfuh2>
                      <header class="mb-2" data-astro-cid-vnivfuh2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                             stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                             data-astro-cid-vnivfuh2>
                          <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                          <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                          <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                          <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                          <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                        </svg>
                        <h1 class="text-xl font-bold text-black" data-astro-cid-vnivfuh2>
                          Tramites personales:
                        </h1></header>
                      <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                           data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                          Nos encargamos de realizar tus gestiones, ahorrándote
                          tiempo y esfuerzo, garantizante que se cumplan los
                          plazos y requisitos necesarios. (tramites bancarios,
                          obra sociales, correo, pagos)
                        </p></div>
                    </article>
                  </div>
                  <div class=" absolute inset-0 scale-[67.5%] z-20 transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)]
                          peer-focus-visible/05:[&_article]:ring
                          peer-focus-visible/05:[&_article]:ring-indigo-300
                          peer-checked/01:translate-x-40
                          peer-checked/02:translate-x-40
                          peer-checked/02:opacity-0
                          peer-checked/03:translate-x-40
                          peer-checked/03:z-30
                          peer-checked/04:translate-x-20
                          peer-checked/04:scale-[83.75%]
                          peer-checked/04:z-40
                          peer-checked/05:relative
                          peer-checked/05:z-50
                          peer-checked/05:translate-x-0
                          peer-checked/05:scale-100
                          peer-checked/05:[&>label]:pointer-events-none" data-astro-cid-vnivfuh2><label
                      class="absolute inset-0" for="article-05" data-astro-cid-vnivfuh2><span class="sr-only"
                                                                                              data-astro-cid-vnivfuh2>Tramites varios:</span></label>
                    <article class="h-[350px] w-[400px] p-6 rounded-lg bg-white shadow-xl"
                             style="border-right: 1rem solid black; border-left: 1px solid black; border-block: 1px solid black"
                             data-astro-cid-vnivfuh2>
                      <header class="mb-2" data-astro-cid-vnivfuh2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                             stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                             data-astro-cid-vnivfuh2>
                          <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                          <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                          <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                          <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                          <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                          <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                        </svg>
                        <h1 class="text-xl font-bold text-black" data-astro-cid-vnivfuh2>
                          Tramites varios:
                        </h1></header>
                      <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                           data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                          Nos encargamos de una amplia variedad de trámites,
                          asegurando que se lleven a cabo con eficiencia, rapidez
                          y total confiabilidad. (cobranzas, envios de dinero,
                          compras, estudios medicos, facturacion, envios de
                          productos)
                        </p></div>
                    </article>
                  </div>
                </div>
              </section> <!-- End: Card slider  --> </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div id="Nosotros" class="valores valoresMovil" data-astro-cid-vnivfuh2>
    <div class="relative my-10" data-astro-cid-vnivfuh2><h3
        class="text-center pt-4 text-3xl leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl sm:leading-10"
        data-astro-cid-vnivfuh2>
        Servicios
      </h3>
      <div class="my-10 space-y-1" data-astro-cid-vnivfuh2></div>
      <div data-astro-cid-vnivfuh2>
        <div
          class="space-y-6 justify-between text-gray-600 md:flex flex-row-reverse md:gap-6 md:space-y-0 lg:gap-12 lg:items-center"
          data-astro-cid-vnivfuh2></div>
        <div class="relative font-inter antialiased" data-astro-cid-vnivfuh2> <!-- MOVIL -->
          <div class="servMovil" data-astro-cid-vnivfuh2>
            <div class="flex gap-8 flex-col" data-astro-cid-vnivfuh2>
              <div class="" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-01"
                                                           data-astro-cid-vnivfuh2><span class="sr-only"
                                                                                         data-astro-cid-vnivfuh2>Servicio coorporativo de entregas</span></label>
                <article class="h-[350px] w-[350px] bg-white p-6 rounded-lg shadow-2xl" style="

  border-right: 1rem solid #fa0019c6;;
      background:white;" data-astro-cid-vnivfuh2>
                  <header class="mb-2" data-astro-cid-vnivfuh2><h1 class="text-xl font-bold text-slate-900"
                                                                   data-astro-cid-vnivfuh2>
                      Servicio coorporativo de entregas
                    </h1></header>
                  <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-8"
                       data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                      Ofrecemos soluciones logísticas confiables y ágiles para
                      empresas que necesitan un manejo profesional de envíos. Nos
                      ocupamos de la entrega rápida de documentos y productos con
                      la garantía, puntualidad y seguridad en cada envío,
                      ajustándonos a las necesidades específicas de tu empresa.
                    </p></div>
                </article>
              </div>
              <div class="" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-02"
                                                           data-astro-cid-vnivfuh2><span class="sr-only"
                                                                                         data-astro-cid-vnivfuh2>Servicio Delivery por hora</span></label>
                <article class="h-[350px] w-[350px] bg-white p-6 rounded-lg shadow-2xl" style="

   border-right: 1rem solid #fa0019c6;;
      background: white;" data-astro-cid-vnivfuh2>
                  <header class="mb-2" data-astro-cid-vnivfuh2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                         data-astro-cid-vnivfuh2>
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                      <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                      <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                      <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                      <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                    </svg>
                    <h1 class="text-xl font-bold text-slate-900" data-astro-cid-vnivfuh2>
                      Servicio Delivery por hora
                    </h1></header>
                  <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                       data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                      Es perfecto para empresas o particulares que requieren
                      entregas continuas o múltiples en un mismo período, sin
                      tener que preocuparse por los costos por envio. Con este
                      servicio, te cobramos por el tiempo de uso del mensajero, no
                      por cada entrega individual.
                    </p></div>
                </article>
              </div>
              <div class="" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-03"
                                                           data-astro-cid-vnivfuh2><span class="sr-only"
                                                                                         data-astro-cid-vnivfuh2>Entregas inmediatas</span></label>
                <article class="h-[350px] w-[350px] bg-white p-6 rounded-lg shadow-2xl" style="

  border-right: 1rem solid #fa0019c6;;
      background: white;" data-astro-cid-vnivfuh2>
                  <header class="mb-2" data-astro-cid-vnivfuh2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                         data-astro-cid-vnivfuh2>
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                      <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                      <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                      <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                      <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                    </svg>
                    <h1 class="text-xl font-bold text-slate-900" data-astro-cid-vnivfuh2>
                      Entregas inmediatas
                    </h1></header>
                  <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                       data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                      Está diseñado para satisfacer las necesidades de los
                      clientes que requieren rapidez y precisión. Sin
                      complicaciones ni demoras, tu paquete o documento llegará a
                      su destino en minutos.
                    </p></div>
                </article>
              </div>
              <div class="" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-04"
                                                           data-astro-cid-vnivfuh2><span class="sr-only"
                                                                                         data-astro-cid-vnivfuh2>Tramites personales</span></label>
                <article class="h-[350px] w-[350px] bg-white p-6 rounded-lg shadow-2xl" style="

  border-right: 1rem solid #fa0019c6;;
      background: white;" data-astro-cid-vnivfuh2>
                  <header class="mb-2" data-astro-cid-vnivfuh2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                         data-astro-cid-vnivfuh2>
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                      <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                      <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                      <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                      <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                    </svg>
                    <h1 class="text-xl font-bold text-slate-900" data-astro-cid-vnivfuh2>
                      Tramites personales
                    </h1></header>
                  <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                       data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                      Nos encargamos de realizar tus gestiones, ahorrándote tiempo
                      y esfuerzo, garantizante que se cumplan los plazos y
                      requisitos necesarios. (tramites bancarios, obra sociales,
                      correo, pagos).
                    </p></div>
                </article>
              </div>
              <div class="" data-astro-cid-vnivfuh2><label class="absolute inset-0" for="article-05"
                                                           data-astro-cid-vnivfuh2><span class="sr-only"
                                                                                         data-astro-cid-vnivfuh2>Tramites varios</span></label>
                <article class="h-[350px] w-[350px] bg-white p-6 rounded-lg shadow-2xl" style="

  border-right: 1rem solid #fa0019c6;;
      background: white;" data-astro-cid-vnivfuh2>
                  <header class="mb-2" data-astro-cid-vnivfuh2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         class="icon icon-tabler icons-tabler-outline icon-tabler-cube-send rounded-xl"
                         data-astro-cid-vnivfuh2>
                      <path stroke="none" d="M0 0h24v24H0z" fill="none" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.5l-5 -3l5 -3l5 3v5.5l-5 3z" data-astro-cid-vnivfuh2></path>
                      <path d="M11 9.5v5.5l5 3" data-astro-cid-vnivfuh2></path>
                      <path d="M16 12.545l5 -3.03" data-astro-cid-vnivfuh2></path>
                      <path d="M7 9h-5" data-astro-cid-vnivfuh2></path>
                      <path d="M7 12h-3" data-astro-cid-vnivfuh2></path>
                      <path d="M7 15h-1" data-astro-cid-vnivfuh2></path>
                    </svg>
                    <h1 class="text-xl font-bold text-slate-900" data-astro-cid-vnivfuh2>
                      Tramites varios
                    </h1></header>
                  <div class="text-gray-500 text-base font-normal leading-relaxed space-y-4 mb-2"
                       data-astro-cid-vnivfuh2><p data-astro-cid-vnivfuh2>
                      Nos encargamos de una amplia variedad de trámites,
                      asegurando que se lleven a cabo con eficiencia, rapidez y
                      total confiabilidad. (cobranzas, envios de dinero, compras,
                      estudios medicos, facturacion, envios de productos).
                    </p></div>
                </article>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="CtaContainer" style="background-color: black;" data-astro-cid-i344ymn4>
    <div class="max-w-7xl mx-auto px-6 md:px-12 xl:px-6">
      <div class="grid grid-cols-2 justify-center items-center movilCTA" data-astro-cid-i344ymn4>
        <div class="content flex justify-center items-center m-auto flex-col gap-5 marginMovil" data-astro-cid-i344ymn4>
          <h3 class="text-5xl text-white font-bold" style="text-shadow: 4px 3px #fa001a;" data-astro-cid-i344ymn4>
            Descarga nuestra APP
          </h3> <h4 class="text-3xl font-bold fadeInUp" style="color: #fa001a;;
    opacity: 0.90;" data-astro-cid-i344ymn4>
            ¡Tus envios mas baratos!
          </h4>
          <p class="text-center text-gray-200" data-astro-cid-i344ymn4>
            Agiliza tus envios descargandote nuestra App, <br data-astro-cid-i344ymn4> cotizá tus envios
            al instante y obtene un seguimiento de cada envio.
          </p>
          <div class="mt-4 flex flex-wrap justify-start gap-y-4 gap-x-6 boton-header" data-astro-cid-i344ymn4><a
              href="https://play.google.com/store/apps/details?id=com.nuggosoft.mensajesapp&hl=es_AR"
              class="relative flex h-12 w-full items-center justify-center px-8 py-6 before:absolute before:inset-0 before:rounded-full before:bg-[#fa001a] before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 sm:w-max"
              data-astro-cid-i344ymn4> <span class="relative text-base font-semibold text-white"
                                             data-astro-cid-i344ymn4>Descargar App</span> </a> <a
              href="https://play.google.com/store/apps/details?id=com.nuggosoft.mensajesapp&hl=es_AR"
              class="relative flex h-12 w-full items-center justify-center px-8 py-6 before:absolute before:inset-0 before:rounded-full before:border-white before:border-2 before:bg-primary/10 before:bg-gradient-to-b before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 dark:before:bg-gray-800 sm:w-max"
              data-astro-cid-i344ymn4> <span class="relative text-base font-semibold text-white dark:text-white"
                                             data-astro-cid-i344ymn4>Descargar App cadete</span> </a> <!-- <a
                      href="#"
                      class="relative flex h-11 w-full items-center justify-center px-6 before:absolute before:inset-0 before:rounded-full before:border before:border-transparent before:bg-primary/10 before:bg-gradient-to-b before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 dark:before:border-gray-700 dark:before:bg-gray-800 sm:w-max"
                    >
                      <span
                        class="relative text-base font-semibold text-primary dark:text-white"
                        >Learn more</span
                      >
                    </a> --> </div>
        </div>
        <div class="container imageMovil" data-astro-cid-i344ymn4><img class="fadeInUp"
                                                                       src="/landing/images/pagina/CTAPP3.png " alt=""
                                                                       data-astro-cid-i344ymn4></div>
      </div>
    </div>
  </div>
  <section class="relative xl:mr-0 lg:mr-5 mr-0" data-astro-cid-bhxv2qxz>
    <div class="w-full max-w-7xl px-4 md:px-5 lg:px-5 mx-auto" data-astro-cid-bhxv2qxz>
      <div class="w-full justify-start items-center xl:gap-12 gap-10 grid lg:grid-cols-2 grid-cols-1"
           data-astro-cid-bhxv2qxz>
        <div class="w-full flex-col justify-center lg:items-start items-center gap-10 inline-flex"
             data-astro-cid-bhxv2qxz>
          <div class="w-full flex-col justify-center items-start gap-8 flex" data-astro-cid-bhxv2qxz>
            <div class="flex-col justify-start lg:items-start items-center gap-4 flex" data-astro-cid-bhxv2qxz>
              <div class="w-full flex-col justify-start lg:items-start items-center gap-3 flex" data-astro-cid-bhxv2qxz>
                <h2 class="text-black text-4xl font-bold font-manrope leading-normal lg:text-start text-center"
                    data-astro-cid-bhxv2qxz>
                  Conoce más sobre nosotros
                </h2>
                <p class="text-gray-500 text-base font-normal leading-relaxed lg:text-start text-center"
                   data-astro-cid-bhxv2qxz>
                  En Mensajes Cadetería, somos más que una empresa de entregas;
                  somos una comunidad comprometida con la eficiencia y la
                  satisfacción del cliente. Desde 2001, hemos estado entregando
                  confiabilidad y dedicación en cada paquete que manejamos.
                  Nuestros valores son nuestro pilar, y nuestra visión es ser tu
                  aliado logístico de confianza en Tucumán.
                </p></div>
            </div>
            <div class="w-full flex-col justify-center items-start gap-6 flex" data-astro-cid-bhxv2qxz>
              <div class="w-full justify-start items-center gap-8 grid md:grid-cols-2 grid-cols-1"
                   data-astro-cid-bhxv2qxz>
                <div
                  class="fadeInUp w-full h-full p-3.5 rounded-xl border transition-all duration-700 ease-in-out flex-col justify-start items-start gap-2.5 inline-flex min-h-[200px]"
                  style="    border: 1px solid #fa001a;" data-astro-cid-bhxv2qxz><h4
                    class="text-gray-900 text-2xl font-bold font-manrope leading-9" data-astro-cid-bhxv2qxz>
                    +23 Años 🙌 ✨
                  </h4>
                  <p class="text-gray-500 text-base font-normal leading-relaxed" data-astro-cid-bhxv2qxz>
                    ¡Gracias por ser parte de nuestra historia y por confiar en
                    nosotros para tus entregas!
                  </p></div>
                <div
                  class="fadeInUp w-full h-full p-3.5 rounded-xl border transition-all duration-700 ease-in-out flex-col justify-start items-start gap-2.5 inline-flex min-h-[200px]"
                  style="    border: 1px solid #fa001a;" data-astro-cid-bhxv2qxz><h4
                    class="text-gray-900 text-2xl font-bold font-manrope leading-9" data-astro-cid-bhxv2qxz>
                    +100 cadetes 📦
                  </h4>
                  <p class="text-gray-500 text-base font-normal leading-relaxed" data-astro-cid-bhxv2qxz>
                    Contamos con más de 100 cadetes distribuidos en toda la
                    ciudad.
                  </p></div>
              </div>
              <div class="w-full h-full justify-start items-center gap-8 grid md:grid-cols-2 grid-cols-1"
                   data-astro-cid-bhxv2qxz>
                <div
                  class="fadeInUp w-full h-full p-3.5 rounded-xl border transition-all duration-700 ease-in-out flex-col justify-start items-start gap-2.5 inline-flex min-h-[200px]"
                  style="    border: 1px solid #fa001a;" data-astro-cid-bhxv2qxz><h4
                    class="text-gray-900 text-2xl font-bold font-manrope leading-9" data-astro-cid-bhxv2qxz>
                    Cadetes geolocalizados 🌐
                  </h4>
                  <p class="text-gray-500 text-base font-normal leading-relaxed" data-astro-cid-bhxv2qxz>
                    Con nuestra app podras hacer el seguimiento de cada envio.
                  </p></div>
                <div
                  class="fadeInUp w-full h-full p-3.5 rounded-xl border transition-all duration-700 ease-in-out flex-col justify-start items-start gap-2.5 inline-flex min-h-[200px]"
                  style="    border: 1px solid #fa001a;" data-astro-cid-bhxv2qxz><h4
                    class="text-gray-900 text-2xl font-bold font-manrope leading-9" data-astro-cid-bhxv2qxz>
                    +5000 clientes 💼
                  </h4>
                  <p class="text-gray-500 text-base font-normal leading-relaxed" data-astro-cid-bhxv2qxz>
                    Con nuestra amplia experiencia logramos obtener más de 5000
                    clientes que confian en nuestro servicio.
                  </p></div>
              </div>
            </div>
          </div> <!-- <button
            class="sm:w-fit w-full group px-3.5 py-2 bg-indigo-50 hover:bg-indigo-100 rounded-lg shadow-[0px_1px_2px_0px_rgba(16,_24,_40,_0.05)] transition-all duration-700 ease-in-out justify-center items-center flex"
          >
            <span
              class="px-1.5 text-indigo-600 text-sm font-medium leading-6 group-hover:-translate-x-0.5 transition-all duration-700 ease-in-out"
              >Read More</span
            >
            <svg
              class="group-hover:translate-x-0.5 transition-all duration-700 ease-in-out"
              xmlns="http://www.w3.org/2000/svg"
              width="18"
              height="18"
              viewBox="0 0 18 18"
              fill="none"
            >
              <path
                d="M6.75265 4.49658L11.2528 8.99677L6.75 13.4996"
                stroke="#4F46E5"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"></path>
            </svg>
          </button> --> </div>
        <div class="w-full justify-center items-center flex" data-astro-cid-bhxv2qxz>
          <video src="/landing/images/pagina/video.mp4" autoplay controls muted
                 class="sm:mt-5 sm:ml-5 rounded-lg object-cover shadow-2xl" data-astro-cid-bhxv2qxz></video> <!-- <div
          class="sm:w-[564px] w-full sm:h-[646px] h-full rounded-3xl sm:border border-gray-200 relative"
          style="background-color: rgb(255,191,191);"
        >
        </div> --> </div>
      </div>
    </div>
  </section> <!-- <div id="Servicios mt-16" class="servicios">
  <Container>
    <div class="grid grid-cols-2 justify-center items-center">
      <div class="container">
        <img src="../../public/landing/images/pagina/Scooter.png" alt="" />
      </div>
      <div
        class="content flex justify-center items-center m-auto flex-col gap-5"
      >
        <h3 class="text-3xl">dasdasdasdasdas</h3>
        <p>
          Lorem ipsum dolor sit, amet consectetur adipisicing elit. Omnis
          laboriosam, tempore obcaecati inventore non dolores quam voluptatum
          minus repudiandae dolore expedita quaerat, voluptas delectus deleniti
          esse adipisci provident vitae aspernatur?
        </p>
      </div>
    </div>
  </Container>
</div> -->
  <div class="relative py-16 contenedorCTA rounded-xl shadow-2xl" data-astro-cid-balv45lp>
    <div aria-hidden="true"
         class="absolute inset-0 h-max w-full m-auto grid grid-cols-2 -space-x-52 opacity-40 dark:opacity-20"
         data-astro-cid-balv45lp>
      <div class="blur-[106px] h-32 bg-gradient-to-r from-[#fa001a]" data-astro-cid-balv45lp></div>
    </div>
    <div class="max-w-7xl mx-auto px-6 md:px-12 xl:px-6">
      <div class="mt-6 m-auto space-y-6" data-astro-cid-balv45lp><h1
          class="text-center text-4xl font-bold text-white md:text-5xl" data-astro-cid-balv45lp>
          Vos vendes, nosotros entregamos. <br data-astro-cid-balv45lp> Solicita tu envío.
        </h1> <!-- <p class="text-center text-xl text-white">¡Contactanós!</p> -->
        <form id="whatsappForm" class="mx-auto mt-16 max-w-xl sm:mt-20" data-astro-cid-balv45lp>
          <div class="grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2" data-astro-cid-balv45lp>
            <div data-astro-cid-balv45lp><label for="desde" class="block text-sm font-semibold leading-6 text-white"
                                                data-astro-cid-balv45lp>Desde</label>
              <div class="mt-2.5" data-astro-cid-balv45lp><input type="text" name="desde" id="desde" autocomplete="off"
                                                                 class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                 data-astro-cid-balv45lp></div>
            </div>
            <div data-astro-cid-balv45lp><label for="hasta" class="block text-sm font-semibold leading-6 text-white"
                                                data-astro-cid-balv45lp>Hasta</label>
              <div class="mt-2.5" data-astro-cid-balv45lp><input type="text" name="hasta" id="hasta" autocomplete="off"
                                                                 class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                 data-astro-cid-balv45lp></div>
            </div>
            <div class="sm:col-span-2" data-astro-cid-balv45lp><label for="company"
                                                                      class="block text-sm font-semibold leading-6 text-white"
                                                                      data-astro-cid-balv45lp>Nombre y Apellido</label>
              <div class="mt-2.5" data-astro-cid-balv45lp><input type="text" name="company" id="company"
                                                                 autocomplete="organization"
                                                                 class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                 data-astro-cid-balv45lp></div>
            </div>
            <div class="sm:col-span-2" data-astro-cid-balv45lp><label for="email"
                                                                      class="block text-sm font-semibold leading-6 text-white"
                                                                      data-astro-cid-balv45lp>Email</label>
              <div class="mt-2.5" data-astro-cid-balv45lp><input type="email" name="email" id="email"
                                                                 autocomplete="email"
                                                                 class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                 data-astro-cid-balv45lp></div>
            </div>
            <div class="sm:col-span-2" data-astro-cid-balv45lp><label for="phone-number"
                                                                      class="block text-sm font-semibold leading-6 text-white"
                                                                      data-astro-cid-balv45lp>Número de telefono</label>
              <div class="relative mt-2.5" data-astro-cid-balv45lp><input type="tel" name="phone-number"
                                                                          id="phone-number" autocomplete="tel"
                                                                          class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                          data-astro-cid-balv45lp></div>
            </div>
            <div class="sm:col-span-2" data-astro-cid-balv45lp><label for="message"
                                                                      class="block text-sm font-semibold leading-6 text-white"
                                                                      data-astro-cid-balv45lp>Indicaciones</label>
              <div class="mt-2.5" data-astro-cid-balv45lp><textarea name="message" id="message" rows="4"
                                                                    class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                    data-astro-cid-balv45lp></textarea></div>
            </div>
          </div>
          <div class="mt-10 flex justify-end" data-astro-cid-balv45lp>
            <button type="submit"
                    class="rounded-full items-center justify-center px-8 before:rounded-full before:bg-[#fa001a] before:transition before:duration-300 hover:before:scale-105 active:duration-75 active:before:scale-95 bg-[#fa001a] py-2.5 text-center text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 text-base font-semibold"
                    data-astro-cid-balv45lp>Enviar pedido por WhatsApp
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</main>
<footer class="w-full relative333.0 pt-10 pb-10 border-t-2" style="background-color: #f7f7f7;" data-astro-cid-so7jxkal>
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" data-astro-cid-so7jxkal>
    <div class="max-w-3xl mx-auto" data-astro-cid-so7jxkal><a href="/#home" aria-label="logo"
                                                              class="flex items-center justify-center space-x-2 logoFoo"
                                                              data-astro-cid-so7jxkal> <img class="w-36"
                                                                                            src="/landing/images/pagina/logo.jpeg"
                                                                                            alt=""
                                                                                            data-astro-cid-so7jxkal>
        <!-- <span class="z text-2xl font-bold text-primary dark:text-white">Zenith <span>Agency</span> </span> --> </a>
      <ul
        class="text-lg flex items-center justify-center flex-col gap-7 md:flex-row md:gap-12 transition-all duration-500 py-10 mb-10 border-b border-gray-200"
        data-astro-cid-so7jxkal>
        <li data-astro-cid-so7jxkal><a href="#" class="text-gray-800 hover:text-gray-900"
                                       data-astro-cid-so7jxkal>Inicio</a></li>
        <li data-astro-cid-so7jxkal><a href="#servicios" class="text-gray-800 hover:text-gray-900" target="_blank"
                                       data-astro-cid-so7jxkal>Servicios</a></li>
        <li data-astro-cid-so7jxkal><a
            href="https://play.google.com/store/apps/details?id=com.nuggosoft.mensajesapp&hl=es_AR" target="_blank"
            class="text-gray-800 hover:text-gray-900" data-astro-cid-so7jxkal>Descargar App</a></li>
        <li data-astro-cid-so7jxkal><a
            href="https://api.whatsapp.com/send?phone=+5493814739347&text=Hola Mensajes Cadeteria, necesito hacer una consulta"
            target="_blank" class="text-gray-800 hover:text-gray-900" data-astro-cid-so7jxkal>Soporte</a></li>
        <li data-astro-cid-so7jxkal><a
            href="https://api.whatsapp.com/send?phone=+5493814739347&text=Hola Mensajes Cadeteria" target="_blank"
            class="text-gray-800 hover:text-gray-900" data-astro-cid-so7jxkal>Contacto</a></li>
      </ul>
      <div class="flex space-x-10 justify-center items-center mb-10" data-astro-cid-so7jxkal><a href="javascript:;"
                                                                                                class="block text-gray-900 transition-all duration-500 hover:text-indigo-600"
                                                                                                data-astro-cid-so7jxkal>
          <svg class="w-[1.688rem] h-[1.688rem]" viewBox="0 0 29 29" fill="none" xmlns="http://www.w3.org/2000/svg"
               data-astro-cid-so7jxkal>
            <path class="text-[#fa001a]"
                  d="M9.76556 14.8811C9.76556 12.3243 11.8389 10.2511 14.3972 10.2511C16.9555 10.2511 19.03 12.3243 19.03 14.8811C19.03 17.4379 16.9555 19.5111 14.3972 19.5111C11.8389 19.5111 9.76556 17.4379 9.76556 14.8811ZM7.26117 14.8811C7.26117 18.82 10.456 22.0129 14.3972 22.0129C18.3385 22.0129 21.5333 18.82 21.5333 14.8811C21.5333 10.9422 18.3385 7.7493 14.3972 7.7493C10.456 7.7493 7.26117 10.9422 7.26117 14.8811ZM20.1481 7.46652C20.148 7.79616 20.2457 8.11843 20.4288 8.39258C20.6119 8.66674 20.8723 8.88046 21.177 9.00673C21.4817 9.133 21.8169 9.16614 22.1405 9.10196C22.464 9.03778 22.7612 8.87916 22.9945 8.64617C23.2278 8.41318 23.3868 8.11627 23.4513 7.79299C23.5157 7.46972 23.4829 7.13459 23.3568 6.83C23.2307 6.5254 23.017 6.26502 22.7428 6.08178C22.4687 5.89853 22.1463 5.80065 21.8164 5.80052H21.8158C21.3737 5.80073 20.9497 5.9763 20.637 6.28867C20.3243 6.60104 20.1485 7.02467 20.1481 7.46652ZM8.78274 26.1863C7.42782 26.1246 6.69138 25.8991 6.20197 25.7085C5.55314 25.4561 5.0902 25.1554 4.60346 24.6696C4.11672 24.1839 3.81543 23.7216 3.56395 23.0732C3.37317 22.5843 3.14748 21.8481 3.08588 20.494C3.01851 19.03 3.00506 18.5902 3.00506 14.8812C3.00506 11.1722 3.01962 10.7336 3.08588 9.26841C3.14759 7.9143 3.37495 7.17952 3.56395 6.68919C3.81654 6.04074 4.11739 5.57808 4.60346 5.09163C5.08953 4.60519 5.55203 4.30408 6.20197 4.05274C6.69116 3.86208 7.42782 3.63652 8.78274 3.57497C10.2476 3.50763 10.6877 3.49419 14.3972 3.49419C18.1068 3.49419 18.5473 3.50874 20.0134 3.57497C21.3683 3.63663 22.1035 3.86385 22.5941 4.05274C23.243 4.30408 23.7059 4.60585 24.1926 5.09163C24.6794 5.57741 24.9796 6.04074 25.2322 6.68919C25.4229 7.17808 25.6486 7.9143 25.7102 9.26841C25.7776 10.7336 25.7911 11.1722 25.7911 14.8812C25.7911 18.5902 25.7776 19.0287 25.7102 20.494C25.6485 21.8481 25.4217 22.5841 25.2322 23.0732C24.9796 23.7216 24.6787 24.1843 24.1926 24.6696C23.7066 25.155 23.243 25.4561 22.5941 25.7085C22.105 25.8992 21.3683 26.1247 20.0134 26.1863C18.5485 26.2536 18.1084 26.2671 14.3972 26.2671C10.686 26.2671 10.2472 26.2536 8.78274 26.1863ZM8.66768 1.0763C7.18823 1.14363 6.17729 1.37808 5.29443 1.72141C4.3801 2.07597 3.60608 2.55163 2.83262 3.32341C2.05916 4.09519 1.58443 4.86997 1.22966 5.78374C0.88612 6.66663 0.651535 7.67641 0.584162 9.15497C0.515676 10.6359 0.5 11.1093 0.5 14.8811C0.5 18.6529 0.515676 19.1263 0.584162 20.6072C0.651535 22.0859 0.88612 23.0955 1.22966 23.9784C1.58443 24.8916 2.05927 25.6673 2.83262 26.4387C3.60597 27.2102 4.3801 27.6852 5.29443 28.0407C6.17896 28.3841 7.18823 28.6185 8.66768 28.6859C10.1502 28.7532 10.6232 28.77 14.3972 28.77C18.1713 28.77 18.645 28.7543 20.1268 28.6859C21.6063 28.6185 22.6166 28.3841 23.5 28.0407C24.4138 27.6852 25.1884 27.2105 25.9618 26.4387C26.7353 25.667 27.209 24.8916 27.5648 23.9784C27.9083 23.0955 28.144 22.0857 28.2103 20.6072C28.2777 19.1252 28.2933 18.6529 28.2933 14.8811C28.2933 11.1093 28.2777 10.6359 28.2103 9.15497C28.1429 7.6763 27.9083 6.66608 27.5648 5.78374C27.209 4.87052 26.7341 4.09641 25.9618 3.32341C25.1896 2.55041 24.4138 2.07597 23.5011 1.72141C22.6166 1.37808 21.6062 1.14252 20.1279 1.0763C18.6461 1.00897 18.1724 0.992188 14.3983 0.992188C10.6243 0.992188 10.1502 1.00785 8.66768 1.0763Z"
                  fill="currentColor" data-astro-cid-so7jxkal></path>
          </svg>
        </a> <a href="javascript:;" class="block text-gray-900 transition-all duration-500 hover:text-indigo-600"
                data-astro-cid-so7jxkal>
          <svg class="w-[0.938rem] h-[1.625rem]" viewBox="0 0 15 26" fill="none" xmlns="http://www.w3.org/2000/svg"
               data-astro-cid-so7jxkal>
            <path class="text-[#fa001a]"
                  d="M13.7926 14.4697L14.5174 9.86889H10.0528V6.87836C10.0528 5.62033 10.6761 4.39105 12.6692 4.39105H14.7275V0.473179C13.5289 0.282204 12.3177 0.178886 11.1037 0.164062C7.42917 0.164062 5.0302 2.37101 5.0302 6.36077V9.86889H0.957031V14.4697H5.0302V25.5979H10.0528V14.4697H13.7926Z"
                  fill="currentColor" data-astro-cid-so7jxkal></path>
          </svg>
        </a> <a href="javascript:;" class="block text-gray-900 transition-all duration-500 hover:text-indigo-600"
                data-astro-cid-so7jxkal>
          <svg class="w-[1.875rem] h-[1.375rem]" viewBox="0 0 30 22" fill="none" xmlns="http://www.w3.org/2000/svg"
               data-astro-cid-so7jxkal>
            <path class="text-[#fa001a]" fill-rule="evenodd" clip-rule="evenodd"
                  d="M26.3106 1.27838C27.5782 1.62071 28.5745 2.61957 28.9113 3.88573C29.524 6.18356 29.524 10.9809 29.524 10.9809C29.524 10.9809 29.524 15.7782 28.9113 18.076C28.5698 19.3469 27.5735 20.3457 26.3106 20.6834C24.0186 21.2977 14.8226 21.2977 14.8226 21.2977C14.8226 21.2977 5.63122 21.2977 3.33456 20.6834C2.06695 20.3411 1.07063 19.3422 0.73385 18.076C0.121094 15.7782 0.121094 10.9809 0.121094 10.9809C0.121094 10.9809 0.121094 6.18356 0.73385 3.88573C1.07531 2.61488 2.07162 1.61602 3.33456 1.27838C5.63122 0.664062 14.8226 0.664062 14.8226 0.664062C14.8226 0.664062 24.0186 0.664062 26.3106 1.27838ZM19.5234 10.9809L11.885 15.403V6.55872L19.5234 10.9809Z"
                  fill="currentColor" data-astro-cid-so7jxkal></path>
          </svg>
        </a></div>
      <p
        class="block mb-4 font-sans text-sm antialiased font-normal leading-normal text-center text-blue-gray-900 md:mb-0"
        data-astro-cid-so7jxkal>
        © 2024 Mensajes - Cadetería
      </p></div>
  </div>
</footer>
<div class="fixed bottom-6 z-40 w-max whatsapp" data-astro-cid-sckkx6r4><a
    href="https://api.whatsapp.com/send?phone=+5493814739347&text=Hola Mensajes, quisiera realizar un envio"
    target="_blank"
    class="flex gap-3 rounded-2xl border cta from-white/50 p-3 shadow-2xl backdrop-blur-xl dark:border-white/10 dark:from-gray-800 dark:to-black/70 dark:shadow-primary/50"
    style="border: none;" data-astro-cid-sckkx6r4> <!-- <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5 text-white ">
                    <path fill-rule="evenodd" d="M9 4.5a.75.75 0 01.721.544l.813 2.846a3.75 3.75 0 002.576 2.576l2.846.813a.75.75 0 010 1.442l-2.846.813a3.75 3.75 0 00-2.576 2.576l-.813 2.846a.75.75 0 01-1.442 0l-.813-2.846a3.75 3.75 0 00-2.576-2.576l-2.846-.813a.75.75 0 010-1.442l2.846-.813A3.75 3.75 0 007.466 7.89l.813-2.846A.75.75 0 019 4.5zM18 1.5a.75.75 0 01.728.568l.258 1.036c.236.94.97 1.674 1.91 1.91l1.036.258a.75.75 0 010 1.456l-1.036.258c-.94.236-1.674.97-1.91 1.91l-.258 1.036a.75.75 0 01-1.456 0l-.258-1.036a2.625 2.625 0 00-1.91-1.91l-1.036-.258a.75.75 0 010-1.456l1.036-.258a2.625 2.625 0 001.91-1.91l.258-1.036A.75.75 0 0118 1.5zM16.5 15a.75.75 0 01.712.513l.394 1.183c.15.447.5.799.948.948l1.183.395a.75.75 0 010 1.422l-1.183.395c-.447.15-.799.5-.948.948l-.395 1.183a.75.75 0 01-1.422 0l-.395-1.183a1.5 1.5 0 00-.948-.948l-1.183-.395a.75.75 0 010-1.422l1.183-.395c.447-.15.799-.5.948-.948l.395-1.183A.75.75 0 0116.5 15z" clip-rule="evenodd"></path>
                </svg> --> <img src="/landing/images/pagina/whatsapp.svg" alt="" data-astro-cid-sckkx6r4> </a></div>
{{-- Alpine, desde acá y no desde un CDN: ver el encabezado del archivo. --}}
<script src="/landing/alpine-3.17.5.min.js" defer></script>
</body>
</html>
