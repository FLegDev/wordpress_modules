(function (wp) {
  const { registerBlockType } = wp.blocks;
  const { InspectorControls } = wp.blockEditor;
  const { PanelBody, SelectControl, TextControl, TextareaControl, ToggleControl, RangeControl } = wp.components;
  const ServerSideRender = wp.serverSideRender;
  const { createElement: el } = wp.element;
  const { __ } = wp.i18n;

  const blockTypes = [
    { label: 'Hero', value: 'hero' },
    { label: 'Feature', value: 'feature' },
    { label: 'Slider', value: 'slider' },
    { label: 'Article card', value: 'article-card' },
    { label: 'Video card', value: 'video-card' },
    { label: 'Masterclass', value: 'masterclass' },
    { label: 'Magazine', value: 'magazine' },
    { label: 'Subscription', value: 'subscription' },
    { label: 'Rail list', value: 'rail-list' },
    { label: 'Agenda', value: 'agenda' },
    { label: 'Poll', value: 'poll' },
    { label: 'Expo logo strip', value: 'expo-logo-strip' },
    { label: 'Ad / Advanced Ads', value: 'ad' },
  ];

  registerBlockType('parresia/template-block', {
    title: 'Parresia block',
    icon: 'screenoptions',
    category: 'widgets',
    description: 'Editorial, video, magazine, sidebar and ad blocks for Parresia templates.',
    attributes: {
      blockType: { type: 'string', default: 'article-card' },
      title: { type: 'string', default: 'Titre du bloc' },
      kicker: { type: 'string', default: 'Actualites' },
      text: { type: 'string', default: 'Texte de description du bloc editorial.' },
      linkUrl: { type: 'string', default: '#' },
      linkLabel: { type: 'string', default: 'Lire' },
      imageUrl: { type: 'string', default: '' },
      theme: { type: 'string', default: 'light' },
      layout: { type: 'string', default: 'card' },
      accent: { type: 'string', default: 'red' },
      itemCount: { type: 'number', default: 3 },
      showViews: { type: 'boolean', default: false },
      views: { type: 'number', default: 0 },
      contentSource: { type: 'string', default: 'manual' },
      postType: { type: 'string', default: 'post' },
      queryTaxonomy: { type: 'string', default: 'category' },
      queryTerms: { type: 'string', default: '' },
      queryCount: { type: 'number', default: 1 },
      queryOrderBy: { type: 'string', default: 'date' },
      viewsMetaKey: { type: 'string', default: 'post_views_count' },
      topMode: { type: 'string', default: 'most-read-posts' },
      kioskPostType: { type: 'string', default: 'kiosque' },
      compact: { type: 'boolean', default: false },
      itemsText: { type: 'string', default: 'Implantologie|Les atouts du cone beam|2400\nStrategie|Developper son cabinet dentaire|1800\nParodontologie|Les espoirs de l intelligence artificielle|980' },
      adMode: { type: 'string', default: 'id' },
      adId: { type: 'string', default: '' },
      adPlacement: { type: 'string', default: '' },
      adShortcode: { type: 'string', default: '' },
    },
    edit: function (props) {
      const attrs = props.attributes;
      const set = (key) => (value) => props.setAttributes({ [key]: value });
      const isAd = attrs.blockType === 'ad';
      const isList = ['rail-list', 'agenda', 'poll'].indexOf(attrs.blockType) !== -1;
      const canQuery = ['hero', 'feature', 'article-card', 'video-card', 'masterclass', 'slider', 'rail-list', 'agenda', 'magazine'].indexOf(attrs.blockType) !== -1;

      return el(
        'div',
        { className: 'parresia-block-editor' },
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: __('Block', 'parresia-blocks'), initialOpen: true },
            el(SelectControl, { label: 'Type', value: attrs.blockType, options: blockTypes, onChange: set('blockType') }),
            el(SelectControl, {
              label: 'Theme',
              value: attrs.theme,
              options: [
                { label: 'Light', value: 'light' },
                { label: 'Dark', value: 'dark' },
                { label: 'Soft', value: 'soft' },
              ],
              onChange: set('theme'),
            }),
            el(SelectControl, {
              label: 'Accent',
              value: attrs.accent,
              options: [
                { label: 'Red / Omni', value: 'red' },
                { label: 'Blue / Ortho', value: 'blue' },
                { label: 'Yellow / Subscribe', value: 'yellow' },
                { label: 'Teal', value: 'teal' },
              ],
              onChange: set('accent'),
            }),
            el(ToggleControl, { label: 'Compact sidebar mode', checked: attrs.compact, onChange: set('compact') })
          ),
          !isAd &&
            el(
              PanelBody,
              { title: __('Content', 'parresia-blocks'), initialOpen: true },
              el(TextControl, { label: 'Kicker', value: attrs.kicker, onChange: set('kicker') }),
              el(TextControl, { label: 'Title', value: attrs.title, onChange: set('title') }),
              el(TextareaControl, { label: 'Text', value: attrs.text, onChange: set('text') }),
              el(TextControl, { label: 'Link URL', value: attrs.linkUrl, onChange: set('linkUrl') }),
              el(TextControl, { label: 'Link label', value: attrs.linkLabel, onChange: set('linkLabel') }),
              el(TextControl, { label: 'Image URL', value: attrs.imageUrl, onChange: set('imageUrl') })
            ),
          canQuery &&
            el(
              PanelBody,
              { title: __('Content query', 'parresia-blocks'), initialOpen: true },
              el(SelectControl, {
                label: 'Source',
                value: attrs.contentSource,
                options: [
                  { label: 'Manual fields', value: 'manual' },
                  { label: 'Automatic query', value: 'query' },
                ],
                onChange: set('contentSource'),
              }),
              el(TextControl, {
                label: 'Post type',
                help: 'Example: post, kiosque, magazine, video, masterclass',
                value: attrs.postType,
                onChange: set('postType'),
              }),
              attrs.blockType === 'magazine' &&
                el(TextControl, {
                  label: 'Kiosk post type',
                  help: 'Used to recover the featured image of the latest kiosk issue.',
                  value: attrs.kioskPostType,
                  onChange: set('kioskPostType'),
                }),
              el(SelectControl, {
                label: 'Taxonomy',
                value: attrs.queryTaxonomy,
                options: [
                  { label: 'Categories', value: 'category' },
                  { label: 'Tags', value: 'post_tag' },
                  { label: 'Custom taxonomy', value: attrs.queryTaxonomy || 'category' },
                ],
                onChange: set('queryTaxonomy'),
              }),
              el(TextControl, {
                label: 'Taxonomy slug',
                help: 'Use category, post_tag, or a custom taxonomy slug.',
                value: attrs.queryTaxonomy,
                onChange: set('queryTaxonomy'),
              }),
              el(TextControl, {
                label: 'Term slugs',
                help: 'Comma-separated slugs, for example implantologie,strategie',
                value: attrs.queryTerms,
                onChange: set('queryTerms'),
              }),
              el(RangeControl, {
                label: 'Articles to fetch',
                value: attrs.queryCount,
                min: 1,
                max: 24,
                onChange: set('queryCount'),
              }),
              el(SelectControl, {
                label: 'Order by',
                value: attrs.queryOrderBy,
                options: [
                  { label: 'Latest publication', value: 'date' },
                  { label: 'Most viewed', value: 'views' },
                ],
                onChange: set('queryOrderBy'),
              }),
              el(TextControl, {
                label: 'Views meta key',
                value: attrs.viewsMetaKey,
                onChange: set('viewsMetaKey'),
              })
            ),
          attrs.blockType === 'rail-list' &&
            el(
              PanelBody,
              { title: __('Top 5 ranking', 'parresia-blocks'), initialOpen: false },
              el(SelectControl, {
                label: 'Ranking mode',
                value: attrs.topMode,
                options: [
                  { label: '5 most read articles', value: 'most-read-posts' },
                  { label: '5 best tags', value: 'top-tags' },
                  { label: '5 best categories', value: 'top-categories' },
                  { label: '5 most viewed kiosk issues', value: 'top-kiosks' },
                ],
                onChange: set('topMode'),
              })
            ),
          el(
            PanelBody,
            { title: __('Metrics and lists', 'parresia-blocks'), initialOpen: false },
            el(ToggleControl, { label: 'Show views', checked: attrs.showViews, onChange: set('showViews') }),
            el(RangeControl, { label: 'Views', value: attrs.views, min: 0, max: 100000, onChange: set('views') }),
            el(RangeControl, { label: 'Number of items', value: attrs.itemCount, min: 1, max: 12, onChange: set('itemCount') }),
            isList && el(TextareaControl, { label: 'Items: kicker|title|views', value: attrs.itemsText, onChange: set('itemsText') })
          ),
          isAd &&
            el(
              PanelBody,
              { title: __('Advanced Ads', 'parresia-blocks'), initialOpen: true },
              el(SelectControl, {
                label: 'Render mode',
                value: attrs.adMode,
                options: [
                  { label: 'Ad ID', value: 'id' },
                  { label: 'Placement ID / slug', value: 'placement' },
                  { label: 'Custom shortcode', value: 'shortcode' },
                ],
                onChange: set('adMode'),
              }),
              el(TextControl, { label: 'Advanced Ads ID', value: attrs.adId, onChange: set('adId') }),
              el(TextControl, { label: 'Advanced Ads placement', value: attrs.adPlacement, onChange: set('adPlacement') }),
              el(TextControl, { label: 'Ad shortcode', value: attrs.adShortcode, onChange: set('adShortcode') })
            )
        ),
        el(ServerSideRender, { block: 'parresia/template-block', attributes: attrs })
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
