/**
 * Image Diagonale — Éditeur Gutenberg (vanilla JS)
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
    var PanelBody        = wp.components.PanelBody;
    var RangeControl     = wp.components.RangeControl;
    var ToggleControl    = wp.components.ToggleControl;
    var SelectControl    = wp.components.SelectControl;
    var TextControl      = wp.components.TextControl;
    var Button           = wp.components.Button;
    var Placeholder      = wp.components.Placeholder;

    var ANGLE_DIRS = [
        { value: 'bottom-right', label: '↘ Coin bas-droit' },
        { value: 'bottom-left',  label: '↙ Coin bas-gauche' },
        { value: 'top-right',    label: '↗ Coin haut-droit' },
        { value: 'top-left',     label: '↖ Coin haut-gauche' },
        { value: 'right',        label: '▷ Bord droit diagonal' },
        { value: 'left',         label: '◁ Bord gauche diagonal' },
    ];

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
        return ( map[ dir ] || map['bottom-right'] ).replace( /PX/g, String( px ) );
    }

    registerBlockType( 'media-slider-blocks/diagonal-image', {

        edit: function ( props ) {
            var a   = props.attributes;
            var set = props.setAttributes;
            var blockProps = useBlockProps();

            var clip = calcClip( a.angleSize, a.angleDirection );

            var inspector = el( InspectorControls, {},

                // ── Image ──────────────────────────────────────────────────────
                el( PanelBody, { title: __( '🖼 Image', 'media-slider-blocks' ), initialOpen: true },
                    el( SelectControl, { label: __( 'Ratio', 'media-slider-blocks' ), value: a.imageRatio,
                        options: [ { value: '16/9', label: '16:9' }, { value: '4/3', label: '4:3' }, { value: '3/2', label: '3:2' }, { value: '1/1', label: '1:1' }, { value: '3/4', label: '3:4' } ],
                        onChange: function ( v ) { set( { imageRatio: v } ); } } ),
                    el( SelectControl, { label: __( 'Recadrage', 'media-slider-blocks' ), value: a.objectFit,
                        options: [ { value: 'cover', label: 'Cover' }, { value: 'contain', label: 'Contain' }, { value: 'fill', label: 'Fill' } ],
                        onChange: function ( v ) { set( { objectFit: v } ); } } ),
                    el( RangeControl, { label: __( 'Arrondi (px)', 'media-slider-blocks' ), value: a.borderRadius, min: 0, max: 32, onChange: function ( v ) { set( { borderRadius: v } ); } } ),
                    a.imageUrl && el( Button, { isDestructive: true, variant: 'secondary', style: { marginTop: '8px' },
                        onClick: function () { set( { imageUrl: '', imageId: 0, imageAlt: '' } ); },
                    }, __( 'Supprimer', 'media-slider-blocks' ) )
                ),

                // ── Angle ───────────────────────────────────────────────────────
                el( PanelBody, { title: __( '⬡ Angle diagonal', 'media-slider-blocks' ), initialOpen: true },
                    el( 'p', { style: { fontSize: '12px', color: '#757575', marginBottom: '10px' } },
                        __( 'Découpe diagonale appliquée à l\'image. 0 = aucune.', 'media-slider-blocks' )
                    ),
                    el( RangeControl, {
                        label:    __( 'Profondeur (px)', 'media-slider-blocks' ),
                        value:    a.angleSize || 0,
                        min: 0, max: 300, step: 5,
                        onChange: function ( v ) { set( { angleSize: v } ); },
                    } ),
                    el( SelectControl, {
                        label:    __( 'Direction', 'media-slider-blocks' ),
                        value:    a.angleDirection || 'bottom-right',
                        options:  ANGLE_DIRS,
                        onChange: function ( v ) { set( { angleDirection: v } ); },
                    } )
                ),

                // ── Lien ────────────────────────────────────────────────────────
                el( PanelBody, { title: __( '🔗 Lien (optionnel)', 'media-slider-blocks' ), initialOpen: false },
                    el( ToggleControl, { label: __( 'Activer la lightbox', 'media-slider-blocks' ), checked: !! a.enableLightbox, onChange: function ( v ) { set( { enableLightbox: v } ); } } ),
                    el( TextControl, { label: __( 'URL', 'media-slider-blocks' ), value: a.linkUrl, type: 'url', onChange: function ( v ) { set( { linkUrl: v } ); } } ),
                    el( ToggleControl, { label: __( 'Nouvel onglet', 'media-slider-blocks' ), checked: a.linkTarget, onChange: function ( v ) { set( { linkTarget: v } ); } } )
                )
            );

            // ── Preview
            var preview = el( MediaUploadCheck, {},
                el( MediaUpload, {
                    onSelect: function ( m ) { set( { imageUrl: m.url, imageId: m.id, imageAlt: m.alt || '' } ); },
                    allowedTypes: [ 'image' ],
                    value: a.imageId,
                    render: function ( ref ) {
                        if ( ! a.imageUrl ) {
                            return el( 'div', {
                                onClick: ref.open,
                                style: { cursor: 'pointer', border: '2px dashed #ccc', borderRadius: '4px', padding: '32px', textAlign: 'center', background: '#f8f8f8' },
                            },
                                el( Placeholder, { icon: 'format-image', label: __( 'Cliquer pour choisir une image', 'media-slider-blocks' ) } )
                            );
                        }
                        return el( 'div', {
                            style: {
                                position: 'relative', aspectRatio: a.imageRatio,
                                overflow: 'hidden', borderRadius: ( a.borderRadius || 0 ) + 'px',
                                clipPath: clip !== 'none' ? clip : undefined,
                                cursor: 'pointer',
                            },
                            onClick: ref.open,
                        },
                            el( 'img', {
                                src: a.imageUrl, alt: a.imageAlt,
                                style: { position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: a.objectFit, display: 'block' },
                            } ),
                            el( 'div', { style: { position: 'absolute', bottom: '6px', right: '6px', background: 'rgba(0,0,0,0.5)', color: '#fff', fontSize: '11px', padding: '3px 8px', borderRadius: '10px' } },
                                __( '✎ Changer', 'media-slider-blocks' )
                            )
                        );
                    },
                } )
            );

            return el( 'div', blockProps, inspector, preview );
        },

        save: function () { return null; },
    } );

}() );
