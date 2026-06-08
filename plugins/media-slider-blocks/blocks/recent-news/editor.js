/**
 * Recent News — Éditeur Gutenberg (vanilla JS, no build)
 *
 * 3 articles éditables :
 *   - Article principal  : grande image + titre + extrait + barre colorée
 *   - Article secondaire 1 & 2 : image petite + titre + extrait + barre
 * Tout éditable inline (RichText) + images via médiathèque
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
    var TextControl      = wp.components.TextControl;
    var ColorPicker      = wp.components.ColorPicker;
    var Button           = wp.components.Button;

    // ── ColorField ─────────────────────────────────────────────────────────────
    function ColorField( label, value, onChange ) {
        return el( 'div', { style: { marginBottom: '16px' } },
            el( 'p', { style: { fontWeight: 600, fontSize: '11px', textTransform: 'uppercase', marginBottom: '6px' } }, label ),
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

    // ── ImagePicker : bouton + prévisualisation ─────────────────────────────────
    function ImagePicker( opts ) {
        // opts : { id, url, alt, onSelect, onRemove, ratio, style }
        return el( MediaUploadCheck, {},
            el( MediaUpload, {
                onSelect: function ( m ) { opts.onSelect( m.url, m.id, m.alt || '' ); },
                allowedTypes: [ 'image' ],
                value: opts.id,
                render: function ( ref ) {
                    if ( opts.url ) {
                        return el( 'div', {
                            style: Object.assign( {
                                position: 'relative',
                                aspectRatio: opts.ratio || '4/3',
                                overflow: 'hidden',
                                cursor: 'pointer',
                                borderRadius: '3px',
                            }, opts.style || {} ),
                            onClick: ref.open,
                        },
                            el( 'img', {
                                src: opts.url,
                                alt: opts.alt,
                                style: { width: '100%', height: '100%', objectFit: 'cover', display: 'block', position: 'absolute', inset: 0 },
                            } ),
                            el( 'div', { style: { position: 'absolute', bottom: '4px', right: '4px', background: 'rgba(0,0,0,.55)', color: '#fff', fontSize: '10px', padding: '2px 7px', borderRadius: '10px' } },
                                '✎ Changer'
                            )
                        );
                    }
                    return el( 'div', {
                        onClick: ref.open,
                        style: Object.assign( {
                            aspectRatio: opts.ratio || '4/3',
                            background: '#e8e8e8',
                            border: '2px dashed #bbb',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            cursor: 'pointer', color: '#888', fontSize: '12px',
                            borderRadius: '3px',
                        }, opts.style || {} ),
                    }, opts.label || __( '+ Image', 'media-slider-blocks' ) );
                },
            } )
        );
    }

    // ── Prévisualisation live ──────────────────────────────────────────────────
    function Preview( props ) {
        var a   = props.attributes;
        var set = props.setAttributes;

        var titleStyle   = { color: a.titleColor,   fontSize: 'clamp(16px, 1.8vw, 22px)', fontWeight: 700, lineHeight: 1.25, margin: '0 0 8px', padding: 0 };
        var excerptStyle = { color: a.excerptColor, fontSize: '14px', lineHeight: 1.6, margin: 0, padding: 0 };

        return el( 'div', { style: { width: '100%' } },

            // ── Titre de section
            el( RichText, {
                tagName:     'h2',
                value:       a.sectionTitle,
                onChange:    function ( v ) { set( { sectionTitle: v } ); },
                placeholder: __( 'Recent News', 'media-slider-blocks' ),
                style:       { textAlign: 'center', fontSize: 'clamp(28px,4vw,48px)', fontWeight: 700, margin: '0 0 28px', padding: 0, color: a.sectionTitleColor },
            } ),

            // ── Grille 2 colonnes
            el( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '28px', alignItems: 'start' } },

                // ── Colonne gauche : article principal
                el( 'div', {},
                    el( ImagePicker, {
                        id: a.mainImageId, url: a.mainImageUrl, alt: a.mainImageAlt,
                        ratio: '4/3',
                        label: __( '+ Image principale', 'media-slider-blocks' ),
                        onSelect: function ( url, id, alt ) { set( { mainImageUrl: url, mainImageId: id, mainImageAlt: alt } ); },
                    } ),

                    el( 'div', { style: { padding: '14px 0 10px' } },
                        el( RichText, {
                            tagName: 'h3', value: a.mainTitle,
                            onChange: function ( v ) { set( { mainTitle: v } ); },
                            placeholder: __( 'Titre de l\'article principal', 'media-slider-blocks' ),
                            style: titleStyle,
                        } ),
                        el( RichText, {
                            tagName: 'p', value: a.mainExcerpt,
                            onChange: function ( v ) { set( { mainExcerpt: v } ); },
                            placeholder: __( 'Extrait…', 'media-slider-blocks' ),
                            style: excerptStyle,
                        } )
                    ),
                    el( 'div', { style: { height: '5px', backgroundColor: a.mainBarColor, borderRadius: '2px', marginTop: '4px' } } )
                ),

                // ── Colonne droite : 2 articles secondaires
                el( 'div', { style: { display: 'flex', flexDirection: 'column', gap: '20px' } },

                    // Article 1
                    el( 'div', {},
                        el( 'div', { style: { display: 'grid', gridTemplateColumns: '40% 1fr', gap: '14px', alignItems: 'start' } },
                            el( ImagePicker, {
                                id: a.news1ImageId, url: a.news1ImageUrl, alt: a.news1ImageAlt,
                                ratio: '4/3',
                                label: __( '+ Image 1', 'media-slider-blocks' ),
                                onSelect: function ( url, id, alt ) { set( { news1ImageUrl: url, news1ImageId: id, news1ImageAlt: alt } ); },
                            } ),
                            el( 'div', {},
                                el( RichText, {
                                    tagName: 'h3', value: a.news1Title,
                                    onChange: function ( v ) { set( { news1Title: v } ); },
                                    placeholder: __( 'Titre article 1', 'media-slider-blocks' ),
                                    style: Object.assign( {}, titleStyle, { fontSize: '17px' } ),
                                } ),
                                el( RichText, {
                                    tagName: 'p', value: a.news1Excerpt,
                                    onChange: function ( v ) { set( { news1Excerpt: v } ); },
                                    placeholder: __( 'Extrait…', 'media-slider-blocks' ),
                                    style: Object.assign( {}, excerptStyle, { fontSize: '13px' } ),
                                } )
                            )
                        ),
                        el( 'div', { style: { height: '5px', backgroundColor: a.news1BarColor, borderRadius: '2px', marginTop: '12px' } } )
                    ),

                    // Article 2
                    el( 'div', {},
                        el( 'div', { style: { display: 'grid', gridTemplateColumns: '40% 1fr', gap: '14px', alignItems: 'start' } },
                            el( ImagePicker, {
                                id: a.news2ImageId, url: a.news2ImageUrl, alt: a.news2ImageAlt,
                                ratio: '4/3',
                                label: __( '+ Image 2', 'media-slider-blocks' ),
                                onSelect: function ( url, id, alt ) { set( { news2ImageUrl: url, news2ImageId: id, news2ImageAlt: alt } ); },
                            } ),
                            el( 'div', {},
                                el( RichText, {
                                    tagName: 'h3', value: a.news2Title,
                                    onChange: function ( v ) { set( { news2Title: v } ); },
                                    placeholder: __( 'Titre article 2', 'media-slider-blocks' ),
                                    style: Object.assign( {}, titleStyle, { fontSize: '17px' } ),
                                } ),
                                el( RichText, {
                                    tagName: 'p', value: a.news2Excerpt,
                                    onChange: function ( v ) { set( { news2Excerpt: v } ); },
                                    placeholder: __( 'Extrait…', 'media-slider-blocks' ),
                                    style: Object.assign( {}, excerptStyle, { fontSize: '13px' } ),
                                } )
                            )
                        ),
                        el( 'div', { style: { height: '5px', backgroundColor: a.news2BarColor, borderRadius: '2px', marginTop: '12px' } } )
                    )
                )
            )
        );
    }

    // ── Panneau Inspector ──────────────────────────────────────────────────────
    registerBlockType( 'media-slider-blocks/recent-news', {

        edit: function ( props ) {
            var a   = props.attributes;
            var set = props.setAttributes;
            var blockProps = useBlockProps();

            var inspector = el( InspectorControls, {},

                // ── Couleurs globales
                el( PanelBody, { title: __( '🎨 Couleurs', 'media-slider-blocks' ), initialOpen: false },
                    ColorField( __( 'Titre section',   'media-slider-blocks' ), a.sectionTitleColor, function ( v ) { set( { sectionTitleColor: v } ); } ),
                    ColorField( __( 'Titres articles', 'media-slider-blocks' ), a.titleColor,        function ( v ) { set( { titleColor:        v } ); } ),
                    ColorField( __( 'Extraits',        'media-slider-blocks' ), a.excerptColor,      function ( v ) { set( { excerptColor:      v } ); } )
                ),

                // ── Barres colorées
                el( PanelBody, { title: __( '▬ Barres colorées', 'media-slider-blocks' ), initialOpen: false },
                    ColorField( __( 'Barre article principal', 'media-slider-blocks' ), a.mainBarColor,  function ( v ) { set( { mainBarColor:  v } ); } ),
                    ColorField( __( 'Barre article 1',         'media-slider-blocks' ), a.news1BarColor, function ( v ) { set( { news1BarColor: v } ); } ),
                    ColorField( __( 'Barre article 2',         'media-slider-blocks' ), a.news2BarColor, function ( v ) { set( { news2BarColor: v } ); } )
                ),

                // ── Liens
                el( PanelBody, { title: __( '🔗 Liens', 'media-slider-blocks' ), initialOpen: false },
                    el( TextControl, { label: __( 'URL article principal', 'media-slider-blocks' ), value: a.mainUrl,  type: 'url', onChange: function ( v ) { set( { mainUrl:  v } ); } } ),
                    el( TextControl, { label: __( 'URL article 1',         'media-slider-blocks' ), value: a.news1Url, type: 'url', onChange: function ( v ) { set( { news1Url: v } ); } } ),
                    el( TextControl, { label: __( 'URL article 2',         'media-slider-blocks' ), value: a.news2Url, type: 'url', onChange: function ( v ) { set( { news2Url: v } ); } } )
                ),

                // ── Supprimer images
                el( PanelBody, { title: __( '🗑 Supprimer des images', 'media-slider-blocks' ), initialOpen: false },
                    a.mainImageUrl  && el( Button, { isDestructive: true, variant: 'secondary', style: { display: 'block', marginBottom: '8px' }, onClick: function () { set( { mainImageUrl:  '', mainImageId:  0, mainImageAlt:  '' } ); } }, __( 'Supprimer image principale', 'media-slider-blocks' ) ),
                    a.news1ImageUrl && el( Button, { isDestructive: true, variant: 'secondary', style: { display: 'block', marginBottom: '8px' }, onClick: function () { set( { news1ImageUrl: '', news1ImageId: 0, news1ImageAlt: '' } ); } }, __( 'Supprimer image article 1',   'media-slider-blocks' ) ),
                    a.news2ImageUrl && el( Button, { isDestructive: true, variant: 'secondary', style: { display: 'block' },                     onClick: function () { set( { news2ImageUrl: '', news2ImageId: 0, news2ImageAlt: '' } ); } }, __( 'Supprimer image article 2',   'media-slider-blocks' ) )
                )
            );

            return el( 'div', blockProps, inspector, el( Preview, { attributes: a, setAttributes: set } ) );
        },

        save: function () { return null; },
    } );

}() );
