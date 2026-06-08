/**
 * Class Card — Éditeur Gutenberg v1.2
 * Angles diagonaux indépendants : Image / Zone Titre / Bouton SEE MORE
 */
( function () {
    'use strict';

    var el               = wp.element.createElement;
    var __               = wp.i18n.__;
    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps    = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var MediaUpload      = wp.blockEditor.MediaUpload;
    var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
    var RichText         = wp.blockEditor.RichText;
    var PanelBody        = wp.components.PanelBody;
    var RangeControl     = wp.components.RangeControl;
    var ToggleControl    = wp.components.ToggleControl;
    var SelectControl    = wp.components.SelectControl;
    var ColorPicker      = wp.components.ColorPicker;
    var TextControl      = wp.components.TextControl;
    var Button           = wp.components.Button;
    var Placeholder      = wp.components.Placeholder;

    // ── ColorField ─────────────────────────────────────────────────────────────
    function ColorField( label, value, onChange ) {
        return el( 'div', { style: { marginBottom: '16px' } },
            el( 'p', { style: { fontWeight: 600, marginBottom: '6px', fontSize: '11px', textTransform: 'uppercase', color: '#1e1e1e' } }, label ),
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

    // ── AnglePanel : panneau réutilisable pour un angle ─────────────────────────
    function AnglePanel( opts ) {
        // opts : { title, angleVal, angleDir, dirOptions, onAngle, onDir, hint }
        return el( PanelBody, { title: opts.title, initialOpen: false },
            opts.hint && el( 'p', { style: { fontSize: '12px', color: '#757575', marginBottom: '12px' } }, opts.hint ),
            el( RangeControl, {
                label:    __( 'Profondeur (px)', 'media-slider-blocks' ),
                value:    opts.angleVal || 0,
                min: 0, max: opts.max || 200, step: opts.step || 5,
                onChange: opts.onAngle,
            } ),
            el( SelectControl, {
                label:    __( 'Direction', 'media-slider-blocks' ),
                value:    opts.angleDir,
                options:  opts.dirOptions,
                onChange: opts.onDir,
            } )
        );
    }

    // ── Options de direction ───────────────────────────────────────────────────
    var IMAGE_DIRS = [
        { value: 'bottom-right', label: '↘ Coin bas-droit' },
        { value: 'bottom-left',  label: '↙ Coin bas-gauche' },
        { value: 'top-right',    label: '↗ Coin haut-droit' },
        { value: 'top-left',     label: '↖ Coin haut-gauche' },
    ];
    var TITLE_DIRS = [
        { value: 'right', label: '▷ Bord droit diagonal (vers le bouton)' },
        { value: 'left',  label: '◁ Bord gauche diagonal' },
    ];
    var BTN_DIRS = [
        { value: 'left',  label: '◁ Bord gauche diagonal (vers le titre)' },
        { value: 'right', label: '▷ Bord droit diagonal' },
    ];

    // ── Calcul clip-path JS (miroir exact du PHP) ──────────────────────────────
    function calcClip( px, dir ) {
        if ( ! px || px <= 0 ) return 'none';
        var map = {
            'bottom-right': 'polygon(0 0,100% 0,calc(100% - PXpx) 100%,0 100%)',
            'bottom-left':  'polygon(0 0,100% 0,100% 100%,PXpx 100%)',
            'top-right':    'polygon(0 0,calc(100% - PXpx) 0,100% PXpx,100% 100%,0 100%)',
            'top-left':     'polygon(PXpx 0,100% 0,100% 100%,0 100%,0 PXpx)',
            'right':        'polygon(0 0,calc(100% - PXpx) 0,100% 100%,0 100%)',
            'left':         'polygon(PXpx 0,100% 0,100% 100%,0 100%)',
        };
        var tpl = map[ dir ] || map['bottom-right'];
        return tpl.replace(/PX/g, String( px ) );
    }

    // ── Prévisualisation live ─────────────────────────────────────────────────
    function CardPreview( props ) {
        var a   = props.attrs;
        var set = props.set;

        var imgClip = calcClip( a.imageAngle,  a.imageAngleDirection );
        var ttlClip = calcClip( a.titleAngle,  a.titleAngleDirection );
        var btnClip = calcClip( a.buttonAngle, a.buttonAngleDirection );

        var btnBg = a.buttonBgImage
            ? { backgroundImage: 'url(' + a.buttonBgImage + ')', backgroundSize: 'cover', backgroundPosition: 'center' }
            : { backgroundColor: a.buttonBgColor };

        return el( 'div', { style: { display: 'flex', flexDirection: 'column', width: '100%' } },

            // ── Image
            el( MediaUploadCheck, {},
                el( MediaUpload, {
                    onSelect: function ( m ) { set( { imageUrl: m.url, imageId: m.id, imageAlt: m.alt || '' } ); },
                    allowedTypes: [ 'image' ],
                    value: a.imageId,
                    render: function ( ref ) {
                        return el( 'div', {
                            onClick: ref.open,
                            style: Object.assign( {
                                position: 'relative', width: '100%', aspectRatio: a.imageRatio,
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                cursor: 'pointer', overflow: 'hidden',
                                clipPath: imgClip,
                            }, a.imageUrl ? {} : { background: '#e8e8e8', border: '2px dashed #ccc' } ),
                        },
                            a.imageUrl
                                ? el( 'img', { src: a.imageUrl, alt: a.imageAlt, style: { width: '100%', height: '100%', objectFit: 'cover', display: 'block', position: 'absolute', inset: 0 } } )
                                : el( Placeholder, { icon: 'format-image', label: __( 'Cliquer pour choisir une image', 'media-slider-blocks' ) } )
                        );
                    },
                } )
            ),

            // ── Barre
            el( 'div', { style: { display: 'flex', alignItems: 'stretch', minHeight: '80px', backgroundColor: a.barBgColor, overflow: 'visible' } },

                // Zone titre
                el( 'div', {
                    style: {
                        flex: '1 1 0', padding: '10px 12px',
                        display: 'flex', flexDirection: 'column', justifyContent: 'center',
                        minWidth: 0, backgroundColor: a.barBgColor,
                        clipPath: ttlClip,
                    },
                },
                    el( RichText, { tagName: 'h3', value: a.title,    onChange: function ( v ) { set( { title: v } ); },    placeholder: __( 'Titre…', 'media-slider-blocks' ),      style: { color: a.titleColor,    fontSize: '20px', fontWeight: 700, margin: 0, padding: 0 } } ),
                    el( RichText, { tagName: 'p',  value: a.subtitle, onChange: function ( v ) { set( { subtitle: v } ); }, placeholder: __( 'Sous-titre…', 'media-slider-blocks' ), style: { color: a.subtitleColor, fontSize: '12px', margin: '2px 0 0', padding: 0 } } )
                ),

                // Bouton SEE MORE
                el( 'div', {
                    style: Object.assign( {
                        flex: '0 0 33%', display: 'flex', alignItems: 'center',
                        justifyContent: 'center', textAlign: 'center',
                        color: a.buttonTextColor, fontSize: '13px', fontWeight: 700,
                        letterSpacing: '.05em', textTransform: 'uppercase',
                        padding: '8px', minWidth: 0, cursor: 'default',
                        clipPath: btnClip,
                    }, btnBg ),
                },
                    el( RichText, { tagName: 'span', value: a.buttonText, onChange: function ( v ) { set( { buttonText: v } ); }, placeholder: __( 'Bouton…', 'media-slider-blocks' ), style: { color: a.buttonTextColor } } )
                )
            )
        );
    }

    // ── Enregistrement ────────────────────────────────────────────────────────
    registerBlockType( 'media-slider-blocks/class-card', {

        edit: function ( props ) {
            var a   = props.attributes;
            var set = props.setAttributes;
            var blockProps = useBlockProps();

            var inspector = el( InspectorControls, {},

                // ── Image ──────────────────────────────────────────────────────
                el( PanelBody, { title: __( '🖼 Image', 'media-slider-blocks' ), initialOpen: true },
                    el( SelectControl, { label: __( 'Ratio', 'media-slider-blocks' ), value: a.imageRatio,
                        options: [ { value: '4/3', label: '4:3' }, { value: '16/9', label: '16:9' }, { value: '3/2', label: '3:2' }, { value: '1/1', label: '1:1' }, { value: '3/4', label: '3:4' } ],
                        onChange: function ( v ) { set( { imageRatio: v } ); } } ),
                    a.imageUrl && el( Button, { isDestructive: true, variant: 'secondary', style: { marginTop: '8px' },
                        onClick: function () { set( { imageUrl: '', imageId: 0, imageAlt: '' } ); },
                    }, __( 'Supprimer l\'image', 'media-slider-blocks' ) )
                ),

                // ── Angle image ────────────────────────────────────────────────
                el( AnglePanel, {
                    title:      '⬡ Angle — Image',
                    hint:       __( 'Coupe diagonale de la photo.', 'media-slider-blocks' ),
                    angleVal:   a.imageAngle,
                    angleDir:   a.imageAngleDirection || 'bottom-right',
                    dirOptions: IMAGE_DIRS,
                    max: 300, step: 5,
                    onAngle: function ( v ) { set( { imageAngle: v } ); },
                    onDir:   function ( v ) { set( { imageAngleDirection: v } ); },
                } ),

                // ── Angle titre ────────────────────────────────────────────────
                el( AnglePanel, {
                    title:      '⬡ Angle — Zone titre',
                    hint:       __( 'Coupe diagonale de la zone "Tender Hearts".', 'media-slider-blocks' ),
                    angleVal:   a.titleAngle,
                    angleDir:   a.titleAngleDirection || 'right',
                    dirOptions: TITLE_DIRS,
                    max: 120, step: 4,
                    onAngle: function ( v ) { set( { titleAngle: v } ); },
                    onDir:   function ( v ) { set( { titleAngleDirection: v } ); },
                } ),

                // ── Angle bouton ───────────────────────────────────────────────
                el( AnglePanel, {
                    title:      '⬡ Angle — Bouton SEE MORE',
                    hint:       __( 'Coupe diagonale du bouton.', 'media-slider-blocks' ),
                    angleVal:   a.buttonAngle,
                    angleDir:   a.buttonAngleDirection || 'left',
                    dirOptions: BTN_DIRS,
                    max: 120, step: 4,
                    onAngle: function ( v ) { set( { buttonAngle: v } ); },
                    onDir:   function ( v ) { set( { buttonAngleDirection: v } ); },
                } ),

                // ── Lien ──────────────────────────────────────────────────────
                el( PanelBody, { title: __( '🔗 Lien', 'media-slider-blocks' ), initialOpen: true },
                    el( TextControl, { label: __( 'URL bouton', 'media-slider-blocks' ), value: a.buttonUrl, type: 'url',
                        onChange: function ( v ) { set( { buttonUrl: v } ); } } ),
                    el( ToggleControl, { label: __( 'Nouvel onglet', 'media-slider-blocks' ), checked: a.buttonTarget,
                        onChange: function ( v ) { set( { buttonTarget: v } ); } } )
                ),

                // ── Couleurs ───────────────────────────────────────────────────
                el( PanelBody, { title: __( '🎨 Couleurs', 'media-slider-blocks' ), initialOpen: false },
                    ColorField( __( 'Fond barre',       'media-slider-blocks' ), a.barBgColor,      function ( v ) { set( { barBgColor:      v } ); } ),
                    ColorField( __( 'Titre',             'media-slider-blocks' ), a.titleColor,      function ( v ) { set( { titleColor:      v } ); } ),
                    ColorField( __( 'Sous-titre',        'media-slider-blocks' ), a.subtitleColor,   function ( v ) { set( { subtitleColor:   v } ); } ),
                    ColorField( __( 'Fond bouton',       'media-slider-blocks' ), a.buttonBgColor,   function ( v ) { set( { buttonBgColor:   v } ); } ),
                    ColorField( __( 'Texte bouton',      'media-slider-blocks' ), a.buttonTextColor, function ( v ) { set( { buttonTextColor: v } ); } )
                ),

                // ── Image fond bouton ──────────────────────────────────────────
                el( PanelBody, { title: __( '🖼 Image fond bouton (optionnel)', 'media-slider-blocks' ), initialOpen: false },
                    el( MediaUploadCheck, {},
                        el( MediaUpload, {
                            onSelect: function ( m ) { set( { buttonBgImage: m.url, buttonBgImageId: m.id } ); },
                            allowedTypes: [ 'image' ],
                            value: a.buttonBgImageId,
                            render: function ( ref ) {
                                return el( 'div', {},
                                    a.buttonBgImage && el( 'img', { src: a.buttonBgImage, style: { width: '100%', maxHeight: '60px', objectFit: 'cover', marginBottom: '8px', borderRadius: '4px' } } ),
                                    el( Button, { onClick: ref.open, variant: 'secondary', style: { marginRight: '8px' } },
                                        a.buttonBgImage ? __( 'Changer', 'media-slider-blocks' ) : __( 'Choisir', 'media-slider-blocks' ) ),
                                    a.buttonBgImage && el( Button, { isDestructive: true, variant: 'secondary',
                                        onClick: function () { set( { buttonBgImage: '', buttonBgImageId: 0 } ); },
                                    }, __( 'Supprimer', 'media-slider-blocks' ) )
                                );
                            },
                        } )
                    )
                )
            );

            return el( 'div', blockProps,
                inspector,
                el( CardPreview, { attrs: a, set: set } )
            );
        },

        save: function () { return null; },
    } );

}() );
