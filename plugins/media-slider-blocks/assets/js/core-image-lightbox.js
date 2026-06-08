( function () {
    'use strict';

    var addFilter = wp.hooks.addFilter;
    var createElement = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var __ = wp.i18n.__;

    addFilter(
        'blocks.registerBlockType',
        'media-slider-blocks/core-image-lightbox-attribute',
        function ( settings, name ) {
            if ( name !== 'core/image' ) {
                return settings;
            }

            settings.attributes = Object.assign( {}, settings.attributes, {
                msbEnableLightbox: {
                    type: 'boolean',
                    default: false,
                },
            } );

            return settings;
        }
    );

    addFilter(
        'editor.BlockEdit',
        'media-slider-blocks/core-image-lightbox-control',
        createHigherOrderComponent( function ( BlockEdit ) {
            return function ( props ) {
                if ( props.name !== 'core/image' ) {
                    return createElement( BlockEdit, props );
                }

                return createElement(
                    Fragment,
                    {},
                    createElement( BlockEdit, props ),
                    createElement(
                        InspectorControls,
                        {},
                        createElement(
                            PanelBody,
                            {
                                title: __( 'Lightbox', 'media-slider-blocks' ),
                                initialOpen: false,
                            },
                            createElement( ToggleControl, {
                                label: __( 'Activer la lightbox', 'media-slider-blocks' ),
                                checked: !! props.attributes.msbEnableLightbox,
                                onChange: function ( value ) {
                                    props.setAttributes( { msbEnableLightbox: value } );
                                },
                            } )
                        )
                    )
                );
            };
        }, 'withMsbCoreImageLightboxControl' )
    );
}() );
