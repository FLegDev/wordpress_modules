/**
 * Media Slider — Éditeur Gutenberg v1.3
 *
 * Fixes :
 *   - Chargement auto des images au montage (useEffect simplifié, sans useCallback)
 *   - Ratio 16:9 forcé dans la prévisualisation éditeur
 *   - Overlay global visible sur les vraies images chargées
 *   - Rafraîchissement automatique quand catégorie / nombre / ordre changent
 */

( function () {
    'use strict';

    var el               = wp.element.createElement;
    var useState         = wp.element.useState;
    var useEffect        = wp.element.useEffect;
    var useRef           = wp.element.useRef;
    var __               = wp.i18n.__;
    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps    = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody        = wp.components.PanelBody;
    var RangeControl     = wp.components.RangeControl;
    var ToggleControl    = wp.components.ToggleControl;
    var SelectControl    = wp.components.SelectControl;
    var TextControl      = wp.components.TextControl;
    var TextareaControl  = wp.components.TextareaControl;
    var ColorPicker      = wp.components.ColorPicker;
    var Button           = wp.components.Button;
    var Spinner          = wp.components.Spinner;
    var useSelect        = wp.data.useSelect;

    // ── Helpers ────────────────────────────────────────────────────────────────

    function ColorField( label, value, onChange ) {
        return el( 'div', { style: { marginBottom: '16px' } },
            el( 'p', { style: { fontWeight: 600, marginBottom: '6px', fontSize: '11px', textTransform: 'uppercase' } }, label ),
            el( ColorPicker, {
                color: value,
                onChangeComplete: function ( c ) {
                    onChange( c.hex || ( c.rgb
                        ? 'rgba(' + c.rgb.r + ',' + c.rgb.g + ',' + c.rgb.b + ',' + c.rgb.a + ')'
                        : value ) );
                },
            } )
        );
    }

    function useCategoryOptions() {
        return useSelect( function ( select ) {
            var terms = select( 'core' ).getEntityRecords( 'taxonomy', 'media_category', { per_page: 100, hide_empty: false } );
            if ( ! terms ) return [];
            return terms.map( function ( t ) { return { value: String( t.id ), label: t.name }; } );
        }, [] );
    }

    function getAjaxUrl() { return ( window.msbEditor || {} ).ajaxUrl || '/wp-admin/admin-ajax.php'; }
    function getNonce()   { return ( window.msbEditor || {} ).nonce   || ''; }

    var POSITIONS = [
        { value: 'top-left',      label: '↖ Haut gauche'    },
        { value: 'top-center',    label: '↑ Haut centre'     },
        { value: 'top-right',     label: '↗ Haut droite'     },
        { value: 'middle-left',   label: '← Milieu gauche'   },
        { value: 'middle-center', label: '⊙ Milieu centre'   },
        { value: 'middle-right',  label: '→ Milieu droite'   },
        { value: 'bottom-left',   label: '↙ Bas gauche'      },
        { value: 'bottom-center', label: '↓ Bas centre'      },
        { value: 'bottom-right',  label: '↘ Bas droite'      },
    ];

    function posToStyle( pos ) {
        var map = {
            'top-left':      { top: '12px',    left: '12px' },
            'top-center':    { top: '12px',    left: '50%', transform: 'translateX(-50%)' },
            'top-right':     { top: '12px',    right: '12px' },
            'middle-left':   { top: '50%',     left: '12px', transform: 'translateY(-50%)' },
            'middle-center': { top: '50%',     left: '50%', transform: 'translate(-50%,-50%)' },
            'middle-right':  { top: '50%',     right: '12px', transform: 'translateY(-50%)' },
            'bottom-left':   { bottom: '12px', left: '12px' },
            'bottom-center': { bottom: '12px', left: '50%', transform: 'translateX(-50%)' },
            'bottom-right':  { bottom: '12px', right: '12px' },
        };
        return map[ pos ] || map['bottom-left'];
    }

    function hexToRgba( hex, opacity ) {
        var clean = ( hex || '#000000' ).replace( '#', '' );
        if ( clean.length === 3 ) clean = clean[0]+clean[0]+clean[1]+clean[1]+clean[2]+clean[2];
        if ( clean.length !== 6 ) return 'rgba(0,0,0,' + ( opacity / 100 ).toFixed(2) + ')';
        var r = parseInt( clean.slice(0,2), 16 );
        var g = parseInt( clean.slice(2,4), 16 );
        var b = parseInt( clean.slice(4,6), 16 );
        return 'rgba(' + r + ',' + g + ',' + b + ',' + ( opacity / 100 ).toFixed(2) + ')';
    }

    // ── Fetch images ───────────────────────────────────────────────────────────
    function doFetchImages( categories, number, orderBy, onSuccess, onDone ) {
        var ajaxUrl = getAjaxUrl();
        var params  = new URLSearchParams( {
            action:   'msb_get_images',
            nonce:    getNonce(),
            number:   number  || 12,
            order_by: orderBy || 'date',
        } );
        ( categories || [] ).forEach( function ( id ) { params.append( 'categories[]', id ); } );

        fetch( ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    params.toString(),
        } )
        .then( function ( r ) { return r.json(); } )
        .then( function ( d ) { if ( d.success && Array.isArray( d.data ) ) onSuccess( d.data ); } )
        .catch( function () {} )
        .finally( onDone );
    }

    // ── Prévisualisation slider ────────────────────────────────────────────────
    function SliderPreview( props ) {
        var a       = props.attributes;
        var images  = props.images;
        var loading = props.loading;

        var vis  = Math.max( 1, Math.min( a.slidesVisible || 3, images.length || 1 ) );
        var gap  = a.gap || 16;

        // Overlay global
        var go        = a.globalOverlay || {};
        var goTitle   = go.title     || '';
        var goText    = go.text      || '';
        var goPos     = go.position  || 'bottom-left';
        var goColor   = go.textColor || '#ffffff';
        var goOpacity = go.opacity   != null ? go.opacity : 50;
        var goBgHex   = go.bgColor   || '#000000';
        var goBg      = hexToRgba( goBgHex, goOpacity );
        var goFSize   = go.fontSize  || 18;
        var hasGO     = goTitle || goText;

        if ( loading ) {
            return el( 'div', {
                style: {
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    aspectRatio: '16/9', background: '#f0f0f0', borderRadius: '4px', gap: '10px',
                },
            },
                el( Spinner ),
                el( 'span', { style: { color: '#777', fontSize: '13px' } }, __( 'Chargement des images…', 'media-slider-blocks' ) )
            );
        }

        if ( ! images || images.length === 0 ) {
            return el( 'div', {
                style: {
                    aspectRatio: '16/9', background: '#f0f0f0', borderRadius: '4px',
                    display: 'flex', flexDirection: 'column', alignItems: 'center',
                    justifyContent: 'center', border: '2px dashed #ccc', gap: '10px',
                },
            },
                el( 'span', { style: { fontSize: '28px' } }, '🎠' ),
                el( 'p', { style: { color: '#666', margin: 0, fontSize: '13px' } },
                    __( 'Aucune image. Sélectionnez une catégorie dans le panneau "Images".', 'media-slider-blocks' )
                )
            );
        }

        // Calcul largeur d'un slide en % pour la prévisualisation
        var slidePercent   = 100 / vis;
        var gapCompensation = Math.round( gap * ( vis - 1 ) / vis );

        return el( 'div', { style: { width: '100%', position: 'relative', borderRadius: '4px', overflow: 'hidden' } },
            // Slides visibles
            el( 'div', { style: { display: 'flex', gap: gap + 'px', width: '100%' } },
                images.slice( 0, vis ).map( function ( img, idx ) {
                    var src = img.src.large || img.src.full || img.src.thumb;
                    return el( 'div', {
                        key: img.id || idx,
                        style: {
                            flex: '0 0 calc(' + slidePercent + '% - ' + gapCompensation + 'px)',
                            minWidth: 0,
                        },
                    },
                        el( 'div', {
                            style: {
                                position: 'relative',
                                aspectRatio: a.aspectRatio || '16/9',
                                overflow: 'hidden',
                                borderRadius: ( a.borderRadius || 0 ) + 'px',
                            },
                        },
                            el( 'img', {
                                src: src,
                                alt: img.alt || img.title,
                                style: {
                                    position: 'absolute', inset: 0,
                                    width: '100%', height: '100%',
                                    objectFit: a.objectFit || 'cover',
                                    display: 'block',
                                },
                            } ),

                            // Overlay global — affiché sur chaque slide
                            hasGO && el( 'div', {
                                style: Object.assign( {
                                    position: 'absolute', zIndex: 3,
                                    padding: '8px 14px', maxWidth: '75%',
                                    background: goBg, borderRadius: '3px',
                                    pointerEvents: 'none',
                                }, posToStyle( goPos ) ),
                            },
                                goTitle && el( 'div', { style: { color: goColor, fontSize: goFSize + 'px', fontWeight: 700, lineHeight: 1.2, marginBottom: goText ? '4px' : 0 } }, goTitle ),
                                goText  && el( 'div', { style: { color: goColor, fontSize: Math.round( goFSize * 0.7 ) + 'px', lineHeight: 1.4, opacity: 0.9 } }, goText )
                            )
                        ),
                        a.displaySlideTitle !== false && el( 'h3', { style: { margin: '10px 0 0', fontSize: '16px', lineHeight: 1.25, fontWeight: 700 } }, img.title || '' )
                    );
                } )
            ),

            // Badge info
            el( 'div', {
                style: {
                    position: 'absolute', bottom: '8px', right: '8px', zIndex: 5,
                    background: 'rgba(0,0,0,.6)', color: '#fff', fontSize: '11px',
                    padding: '3px 10px', borderRadius: '12px',
                },
            }, images.length + ' img · ' + vis + ' visible' + ( vis > 1 ? 's' : '' ) )
        );
    }

    // ── Panneau overlay par slide ──────────────────────────────────────────────
    function SlideOverlayPanel( props ) {
        var a        = props.attributes;
        var set      = props.setAttributes;
        var images   = props.images;
        var overlays = a.slideOverlays || {};

        function upd( id, key, val ) {
            var next = Object.assign( {}, overlays );
            if ( ! next[ id ] ) next[ id ] = { text: '', position: 'bottom-left', textColor: '#ffffff', bgColor: 'rgba(0,0,0,0.55)', fontSize: 16 };
            next[ id ][ key ] = val;
            set( { slideOverlays: next } );
        }
        function del( id ) {
            var next = Object.assign( {}, overlays );
            delete next[ id ];
            set( { slideOverlays: next } );
        }

        if ( ! images || images.length === 0 ) {
            return el( 'p', { style: { color: '#888', fontSize: '12px' } },
                __( 'Les images apparaîtront ici une fois chargées.', 'media-slider-blocks' )
            );
        }

        return el( 'div', {},
            images.map( function ( img ) {
                var ov  = overlays[ img.id ] || null;
                var has = ov && ov.text;
                return el( 'div', { key: img.id, style: { marginBottom: '10px', border: '1px solid #e0e0e0', borderRadius: '6px', overflow: 'hidden' } },
                    el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '6px 8px', background: '#fafafa', borderBottom: '1px solid #eee' } },
                        el( 'img', { src: img.src.thumb || img.src.large, style: { width: '44px', height: '32px', objectFit: 'cover', borderRadius: '2px', flexShrink: 0 } } ),
                        el( 'span', { style: { fontSize: '11px', fontWeight: 600, flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, img.title || 'Image ' + img.id ),
                        el( Button, { variant: has ? 'primary' : 'secondary', isSmall: true,
                            onClick: function () { has ? del( img.id ) : upd( img.id, 'text', img.title || '' ); },
                        }, has ? '✓' : '+' )
                    ),
                    has && el( 'div', { style: { padding: '8px' } },
                        el( TextareaControl, { label: __( 'Texte', 'media-slider-blocks' ), value: ov.text || '', rows: 2, onChange: function ( v ) { upd( img.id, 'text', v ); } } ),
                        el( SelectControl, { label: __( 'Position', 'media-slider-blocks' ), value: ov.position || 'bottom-left', options: POSITIONS, onChange: function ( v ) { upd( img.id, 'position', v ); } } ),
                        el( RangeControl, { label: __( 'Taille (px)', 'media-slider-blocks' ), value: ov.fontSize || 16, min: 10, max: 60, onChange: function ( v ) { upd( img.id, 'fontSize', v ); } } ),
                        el( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px' } },
                            ColorField( __( 'Texte', 'media-slider-blocks' ), ov.textColor || '#ffffff', function ( v ) { upd( img.id, 'textColor', v ); } ),
                            ColorField( __( 'Fond',  'media-slider-blocks' ), ov.bgColor   || 'rgba(0,0,0,.55)', function ( v ) { upd( img.id, 'bgColor', v ); } )
                        )
                    )
                );
            } )
        );
    }

    // ── Bloc principal ─────────────────────────────────────────────────────────
    registerBlockType( 'media-slider-blocks/media-slider', {

        edit: function ( props ) {
            var a   = props.attributes;
            var set = props.setAttributes;
            var blockProps  = useBlockProps();
            var catOptions  = useCategoryOptions();

            var imagesState  = useState( [] );
            var images       = imagesState[0];
            var setImages    = imagesState[1];

            var loadingState = useState( false );
            var loading      = loadingState[0];
            var setLoading   = loadingState[1];

            // Ref pour éviter plusieurs fetch simultanés
            var fetchRef = useRef( false );
            var mountRef = useRef( false );

            function loadImages() {
                if ( fetchRef.current ) return;
                fetchRef.current = true;
                setLoading( true );
                doFetchImages(
                    a.categories,
                    a.numberOfImages,
                    a.orderBy,
                    function ( data ) { setImages( data ); },
                    function () { setLoading( false ); fetchRef.current = false; }
                );
            }

            // ── Chargement au montage (1 seule fois) ─────────────────────────
            useEffect( function () {
                if ( ! mountRef.current ) {
                    mountRef.current = true;
                    loadImages();
                }
            }, [] ); // eslint-disable-line

            // ── Rechargement quand les filtres changent ───────────────────────
            var catKey    = JSON.stringify( ( a.categories || [] ).slice().sort() );
            var filterKey = catKey + '|' + a.numberOfImages + '|' + a.orderBy;
            var prevFilterRef = useRef( filterKey );

            useEffect( function () {
                if ( prevFilterRef.current !== filterKey ) {
                    prevFilterRef.current = filterKey;
                    loadImages();
                }
            }, [ filterKey ] ); // eslint-disable-line

            // Overlay global
            var go  = a.globalOverlay || {};
            function setGO( key, val ) {
                set( { globalOverlay: Object.assign( {}, go, { [key]: val } ) } );
            }
            var goActive = go.title || go.text;

            var inspector = el( InspectorControls, {},

                // ── Images ─────────────────────────────────────────────────────
                el( PanelBody, { title: __( '🖼 Images', 'media-slider-blocks' ), initialOpen: true },
                    catOptions.length > 0 && el( 'div', { style: { marginBottom: '14px' } },
                        el( 'p', { style: { fontWeight: 600, marginBottom: '8px' } }, __( 'Catégories', 'media-slider-blocks' ) ),
                        catOptions.map( function ( opt ) {
                            var chk = ( a.categories || [] ).includes( opt.value );
                            return el( 'label', { key: opt.value, style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px', cursor: 'pointer' } },
                                el( 'input', { type: 'checkbox', checked: chk, onChange: function () {
                                    var next = chk
                                        ? ( a.categories || [] ).filter( function ( v ) { return v !== opt.value; } )
                                        : ( a.categories || [] ).concat( [ opt.value ] );
                                    set( { categories: next } );
                                } } ), opt.label
                            );
                        } )
                    ),
                    el( RangeControl, { label: __( 'Nombre d\'images', 'media-slider-blocks' ), value: a.numberOfImages, min: 1, max: 50, onChange: function ( v ) { set( { numberOfImages: v } ); } } ),
                    el( SelectControl, { label: __( 'Trier par', 'media-slider-blocks' ), value: a.orderBy,
                        options: [ { value: 'date', label: 'Date' }, { value: 'title', label: 'Titre' }, { value: 'rand', label: 'Aléatoire' } ],
                        onChange: function ( v ) { set( { orderBy: v } ); } } ),
                    el( ToggleControl, { label: __( 'Afficher le titre sous l’image', 'media-slider-blocks' ), checked: a.displaySlideTitle !== false, onChange: function ( v ) { set( { displaySlideTitle: v } ); } } ),
                    el( ToggleControl, { label: __( 'Lightbox galerie', 'media-slider-blocks' ), checked: a.linkImages, onChange: function ( v ) { set( { linkImages: v } ); } } ),
                    el( Button, { variant: 'secondary', onClick: loadImages, isBusy: loading, style: { marginTop: '6px' } },
                        loading ? __( 'Chargement…', 'media-slider-blocks' ) : __( '⟳ Rafraîchir', 'media-slider-blocks' ) )
                ),

                // ── Dimensions ──────────────────────────────────────────────────
                el( PanelBody, { title: __( '📐 Dimensions', 'media-slider-blocks' ), initialOpen: false },
                    el( TextControl, {
                        label:    __( 'Largeur max', 'media-slider-blocks' ),
                        value:    a.maxWidth || '100%',
                        onChange: function ( v ) { set( { maxWidth: v } ); },
                        help:     __( 'Ex: 100%, 800px, 1200px. Défaut: 100%', 'media-slider-blocks' ),
                    } ),
                    el( TextControl, {
                        label:    __( 'Hauteur fixe (optionnel)', 'media-slider-blocks' ),
                        value:    a.blockHeight || '',
                        onChange: function ( v ) { set( { blockHeight: v } ); },
                        help:     __( 'Ex: 400px, 50vh. Laissez vide pour utiliser le ratio automatique.', 'media-slider-blocks' ),
                    } )
                ),

                // ── Overlay global ──────────────────────────────────────────────
                el( PanelBody, { title: '💬 Overlay global' + ( goActive ? ' ✓' : '' ), initialOpen: false },

                    // Encadré explicatif
                    el( 'div', { style: { background: '#f0f6fc', border: '1px solid #c0d8f0', borderRadius: '4px', padding: '10px 12px', marginBottom: '14px', fontSize: '12px', lineHeight: 1.5 } },
                        el( 'strong', {}, '📝 Comment utiliser l\'overlay :' ),
                        el( 'ol', { style: { margin: '6px 0 0', paddingLeft: '18px' } },
                            el( 'li', {}, __( 'Saisissez un titre et/ou un sous-texte ci-dessous', 'media-slider-blocks' ) ),
                            el( 'li', {}, __( 'Choisissez la position (ex: milieu gauche)', 'media-slider-blocks' ) ),
                            el( 'li', {}, __( 'Ajustez transparence, taille et couleurs', 'media-slider-blocks' ) ),
                            el( 'li', {}, __( 'L\'overlay s\'affiche sur toutes les slides', 'media-slider-blocks' ) )
                        )
                    ),

                    el( TextControl, {
                        label:    __( 'Titre (gros texte)', 'media-slider-blocks' ),
                        value:    go.title || '',
                        onChange: function ( v ) { setGO( 'title', v ); },
                        placeholder: 'Welcoming Place for Every Kid',
                    } ),
                    el( TextareaControl, {
                        label:    __( 'Sous-texte', 'media-slider-blocks' ),
                        value:    go.text || '',
                        rows:     2,
                        onChange: function ( v ) { setGO( 'text', v ); },
                        placeholder: 'Great experience and progress for your child',
                    } ),
                    el( SelectControl, {
                        label:    __( 'Position sur l\'image', 'media-slider-blocks' ),
                        value:    go.position || 'bottom-left',
                        options:  POSITIONS,
                        onChange: function ( v ) { setGO( 'position', v ); },
                    } ),
                    el( RangeControl, {
                        label:    __( 'Taille titre (px)', 'media-slider-blocks' ),
                        value:    go.fontSize || 18,
                        min: 12, max: 72,
                        onChange: function ( v ) { setGO( 'fontSize', v ); },
                    } ),
                    el( RangeControl, {
                        label:    __( 'Opacité du fond', 'media-slider-blocks' ),
                        value:    go.opacity != null ? go.opacity : 50,
                        min: 0, max: 100,
                        help: '0 = transparent · 100 = opaque',
                        onChange: function ( v ) { setGO( 'opacity', v ); },
                    } ),
                    el( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' } },
                        ColorField( __( 'Couleur texte', 'media-slider-blocks' ), go.textColor || '#ffffff', function ( v ) { setGO( 'textColor', v ); } ),
                        ColorField( __( 'Couleur fond',  'media-slider-blocks' ), go.bgColor   || '#000000', function ( v ) { setGO( 'bgColor', v ); } )
                    ),
                    goActive && el( Button, { isDestructive: true, variant: 'secondary', style: { marginTop: '8px' },
                        onClick: function () { set( { globalOverlay: {} } ); },
                    }, __( 'Effacer l\'overlay', 'media-slider-blocks' ) )
                ),

                // ── Overlay par slide ───────────────────────────────────────────
                el( PanelBody, { title: __( '💬 Overlay par slide', 'media-slider-blocks' ), initialOpen: false },
                    el( SlideOverlayPanel, { attributes: a, setAttributes: set, images: images } )
                ),

                // ── Angle diagonal ──────────────────────────────────────────────
                el( PanelBody, { title: __( '⬡ Angle diagonal', 'media-slider-blocks' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Profondeur (px)', 'media-slider-blocks' ),
                        value: a.imageAngle || 0, min: 0, max: 200, step: 5,
                        onChange: function ( v ) { set( { imageAngle: v } ); },
                    } ),
                    el( SelectControl, {
                        label: __( 'Direction', 'media-slider-blocks' ),
                        value: a.angleDirection || 'right',
                        options: [
                            { value: 'right', label: '↗ Coin haut-droit' },
                            { value: 'left',  label: '↖ Coin haut-gauche' },
                        ],
                        onChange: function ( v ) { set( { angleDirection: v } ); },
                    } )
                ),

                // ── Paramètres slider ───────────────────────────────────────────
                el( PanelBody, { title: __( '⚙ Paramètres slider', 'media-slider-blocks' ), initialOpen: false },
                    el( RangeControl, { label: __( 'Slides visibles (desktop)', 'media-slider-blocks' ), value: a.slidesVisible, min: 1, max: 6, onChange: function ( v ) { set( { slidesVisible: v } ); } } ),
                    el( RangeControl, { label: __( 'Slides (tablette)', 'media-slider-blocks' ),         value: a.slidesTablet,  min: 1, max: 4, onChange: function ( v ) { set( { slidesTablet: v } ); } } ),
                    el( RangeControl, { label: __( 'Slides (mobile)', 'media-slider-blocks' ),           value: a.slidesMobile,  min: 1, max: 2, onChange: function ( v ) { set( { slidesMobile: v } ); } } ),
                    el( RangeControl, { label: __( 'Gap (px)', 'media-slider-blocks' ), value: a.gap, min: 0, max: 80, step: 2, onChange: function ( v ) { set( { gap: v } ); } } ),
                    el( ToggleControl, { label: __( 'Autoplay', 'media-slider-blocks' ), checked: a.autoplay, onChange: function ( v ) { set( { autoplay: v } ); } } ),
                    a.autoplay && el( RangeControl, { label: __( 'Vitesse autoplay (ms)', 'media-slider-blocks' ), value: a.autoplaySpeed, min: 1000, max: 10000, step: 500, onChange: function ( v ) { set( { autoplaySpeed: v } ); } } ),
                    el( RangeControl, { label: __( 'Transition (ms)', 'media-slider-blocks' ), value: a.transitionSpeed, min: 100, max: 2000, step: 100, onChange: function ( v ) { set( { transitionSpeed: v } ); } } ),
                    el( ToggleControl, { label: __( 'Flèches', 'media-slider-blocks' ), checked: a.showArrows, onChange: function ( v ) { set( { showArrows: v } ); } } ),
                    el( ToggleControl, { label: __( 'Points',  'media-slider-blocks' ), checked: a.showDots,   onChange: function ( v ) { set( { showDots: v } ); } } ),
                    el( ToggleControl, { label: __( 'Boucle',  'media-slider-blocks' ), checked: a.loop,       onChange: function ( v ) { set( { loop: v } ); } } )
                ),

                // ── Style image ─────────────────────────────────────────────────
                el( PanelBody, { title: __( '🎨 Style image', 'media-slider-blocks' ), initialOpen: false },
                    el( SelectControl, { label: __( 'Ratio', 'media-slider-blocks' ), value: a.aspectRatio,
                        options: [ { value: '16/9', label: '16:9 (recommandé)' }, { value: '4/3', label: '4:3' }, { value: '1/1', label: '1:1' }, { value: '3/2', label: '3:2' }, { value: '3/4', label: '3:4' } ],
                        onChange: function ( v ) { set( { aspectRatio: v } ); } } ),
                    el( SelectControl, { label: __( 'Fit', 'media-slider-blocks' ), value: a.objectFit,
                        options: [ { value: 'cover', label: 'Cover' }, { value: 'contain', label: 'Contain' }, { value: 'fill', label: 'Fill' } ],
                        onChange: function ( v ) { set( { objectFit: v } ); } } ),
                    el( RangeControl, { label: __( 'Arrondi (px)', 'media-slider-blocks' ), value: a.borderRadius, min: 0, max: 32, onChange: function ( v ) { set( { borderRadius: v } ); } } )
                )
            );

            return el( 'div', blockProps,
                inspector,
                el( SliderPreview, { attributes: a, images: images, loading: loading } )
            );
        },

        save: function () { return null; },
    } );

}() );
