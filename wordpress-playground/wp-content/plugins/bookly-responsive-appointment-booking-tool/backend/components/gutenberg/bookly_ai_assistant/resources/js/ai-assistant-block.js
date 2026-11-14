(function (wp) {
    var el = wp.element.createElement,
        components = wp.components,
        InspectorControls = wp.editor.InspectorControls
    ;

    function shortCode(token) {
        return '[bookly-ai-assistant-form' + (token ? ' ' + token : '') + ']';
    }

    function colorForToken(token) {
        var form = BooklyAiAssistantBlockL10n.forms.filter(function (f) {
            return f.token === token;
        })[0];
        return (form || {}).color || BooklyAiAssistantBlockL10n.defaultColor;
    }

    function icon(color) {
        return el('svg', {width: '24', height: '24', viewBox: '0 0 24 24'},
            el('circle', {cx: '12', cy: '12', r: '12', fill: color}),
            el('text', {
                x: '12', y: '12', dy: '0.35em',
                textAnchor: 'middle',
                fill: '#fff',
                fontSize: '9',
                fontWeight: 'bold',
                fontFamily: 'sans-serif'
            }, 'AI')
        );
    }

    wp.blocks.registerBlockType('bookly/ai-assistant', {
        title: BooklyAiAssistantBlockL10n.block.title,
        description: BooklyAiAssistantBlockL10n.block.description,
        icon: icon(colorForToken((BooklyAiAssistantBlockL10n.forms[0] || {}).token)),
        category: 'bookly-blocks',
        keywords: [
            'bookly',
            'ai',
            'assistant',
            'chat',
        ],
        supports: {
            customClassName: false,
            html: false
        },
        attributes: {
            token: {
                type: 'string',
                default: (BooklyAiAssistantBlockL10n.forms[0] || {}).token || ''
            }
        },
        edit: function (props) {
            var token = props.attributes.token,
                children = []
            ;

            if (BooklyAiAssistantBlockL10n.forms.length > 1) {
                children.push(el(InspectorControls, {key: 'inspector'},
                    el(components.SelectControl, {
                        label: BooklyAiAssistantBlockL10n.selectForm,
                        value: token,
                        options: BooklyAiAssistantBlockL10n.forms.map(function (form) {
                            return {label: form.name, value: form.token};
                        }),
                        onChange: function (value) {
                            props.setAttributes({token: value});
                        }
                    })
                ));
            }

            children.push(el('div', {
                    key: 'preview',
                    style: {display: 'flex', alignItems: 'center', gap: '0.5em', padding: '1em', border: '1px dashed #ccc'}
                },
                    icon(colorForToken(token)),
                    el('span', {}, shortCode(token))
                )
            );

            return children;
        },
        save: function (props) {
            return el('div', {}, shortCode(props.attributes.token));
        }
    });
})(
    window.wp
);
