/**
 * Gated Video Player Block
 * A Gutenberg block for displaying gated video content with JWT validation
 */

const { registerBlockType } = wp.blocks;
const { __ } = wp.i18n;
const { createElement } = wp.element;
const { 
    InspectorControls,
    useBlockProps,
    RichText,
    SelectControl,
    ToggleControl,
    PanelBody,
    TextControl,
    TextareaControl
} = wp.blockEditor;
const { 
    SelectControl: SelectControlComponent,
    ToggleControl: ToggleControlComponent,
    PanelBody: PanelBodyComponent,
    TextControl: TextControlComponent,
    TextareaControl: TextareaControlComponent
} = wp.components;
const { useState, useEffect } = wp.element;

registerBlockType('lem/gated-video', {
    title: __('Gated Video Player', 'live-event-manager'),
    description: __('Display a video player that requires a valid ticket to watch.', 'live-event-manager'),
    category: 'media',
    icon: 'video-alt3',
    keywords: [
        __('video', 'live-event-manager'),
        __('player', 'live-event-manager'),
        __('gated', 'live-event-manager'),
        __('live', 'live-event-manager'),
        __('stream', 'live-event-manager')
    ],
    supports: {
        html: false,
        align: ['wide', 'full']
    },
    attributes: {
        eventId: {
            type: 'number',
            default: null
        },
        playbackId: {
            type: 'string',
            default: ''
        },
        title: {
            type: 'string',
            default: 'Live Event'
        },
        description: {
            type: 'string',
            default: ''
        },
        aspectRatio: {
            type: 'string',
            default: '16/9'
        },
        showControls: {
            type: 'boolean',
            default: true
        },
        autoplay: {
            type: 'boolean',
            default: false
        },
        muted: {
            type: 'boolean',
            default: false
        },
        theme: {
            type: 'string',
            default: 'dark'
        }
    },
    
    edit: function(props) {
        const { attributes, setAttributes } = props;
        const { 
            eventId, 
            playbackId, 
            title, 
            description, 
            aspectRatio, 
            showControls, 
            autoplay, 
            muted, 
            theme 
        } = attributes;
        
        const [events, setEvents] = useState([]);
        const [loading, setLoading] = useState(false);
        
        // Load events on component mount
        useEffect(() => {
            loadEvents();
        }, []);
        
        const loadEvents = async () => {
            setLoading(true);
            try {
                const response = await fetch('/wp-json/wp/v2/lem_event?per_page=100');
                const eventsData = await response.json();
                setEvents(eventsData);
            } catch (error) {
                console.error('Error loading events:', error);
            } finally {
                setLoading(false);
            }
        };
        
        const aspectRatioOptions = [
            { label: '16:9', value: '16/9' },
            { label: '4:3', value: '4/3' },
            { label: '1:1', value: '1/1' },
            { label: '21:9', value: '21/9' }
        ];
        
        const themeOptions = [
            { label: 'Dark', value: 'dark' },
            { label: 'Light', value: 'light' },
            { label: 'Minimal', value: 'minimal' }
        ];
        
        const blockProps = useBlockProps();
        
        return createElement('div', blockProps, [
            createElement(InspectorControls, { key: 'inspector' }, [
                createElement(PanelBodyComponent, { 
                    key: 'video-settings',
                    title: __('Video Settings', 'live-event-manager'), 
                    initialOpen: true 
                }, [
                    createElement(SelectControlComponent, {
                        key: 'event-select',
                        label: __('Event', 'live-event-manager'),
                        value: eventId,
                        options: [
                            { label: __('Select an event...', 'live-event-manager'), value: '' },
                            ...events.map(event => ({
                                label: event.title.rendered,
                                value: event.id
                            }))
                        ],
                        onChange: (value) => setAttributes({ eventId: value ? parseInt(value) : null }),
                        disabled: loading
                    }),
                    
                    createElement(TextControlComponent, {
                        key: 'playback-id',
                        label: __('Playback ID (Optional)', 'live-event-manager'),
                        value: playbackId,
                        onChange: (value) => setAttributes({ playbackId: value }),
                        help: __('Leave empty to use the event\'s playback ID', 'live-event-manager')
                    }),
                    
                    createElement(TextControlComponent, {
                        key: 'title',
                        label: __('Title', 'live-event-manager'),
                        value: title,
                        onChange: (value) => setAttributes({ title: value })
                    }),
                    
                    createElement(TextareaControlComponent, {
                        key: 'description',
                        label: __('Description', 'live-event-manager'),
                        value: description,
                        onChange: (value) => setAttributes({ description: value })
                    }),
                    
                    createElement(SelectControlComponent, {
                        key: 'aspect-ratio',
                        label: __('Aspect Ratio', 'live-event-manager'),
                        value: aspectRatio,
                        options: aspectRatioOptions,
                        onChange: (value) => setAttributes({ aspectRatio: value })
                    }),
                    
                    createElement(SelectControlComponent, {
                        key: 'theme',
                        label: __('Theme', 'live-event-manager'),
                        value: theme,
                        options: themeOptions,
                        onChange: (value) => setAttributes({ theme: value })
                    })
                ]),
                
                createElement(PanelBodyComponent, { 
                    key: 'player-controls',
                    title: __('Player Controls', 'live-event-manager'), 
                    initialOpen: false 
                }, [
                    createElement(ToggleControlComponent, {
                        key: 'show-controls',
                        label: __('Show Controls', 'live-event-manager'),
                        checked: showControls,
                        onChange: (value) => setAttributes({ showControls: value })
                    }),
                    
                    createElement(ToggleControlComponent, {
                        key: 'autoplay',
                        label: __('Autoplay', 'live-event-manager'),
                        checked: autoplay,
                        onChange: (value) => setAttributes({ autoplay: value })
                    }),
                    
                    createElement(ToggleControlComponent, {
                        key: 'muted',
                        label: __('Muted', 'live-event-manager'),
                        checked: muted,
                        onChange: (value) => setAttributes({ muted: value })
                    })
                ])
            ]),
            
            createElement('div', { 
                key: 'preview',
                className: 'lem-gated-video-preview' 
            }, [
                createElement('div', { 
                    key: 'header',
                    className: 'lem-preview-header' 
                }, [
                    createElement('h3', { key: 'title' }, __('Gated Video Player', 'live-event-manager')),
                    createElement('div', { 
                        key: 'status',
                        className: 'lem-preview-status' 
                    }, eventId ? 
                        createElement('span', { 
                            key: 'connected',
                            className: 'lem-status-connected' 
                        }, [
                            createElement('span', { key: 'dot', className: 'lem-status-dot' }),
                            __('Event Connected', 'live-event-manager')
                        ]) : 
                        createElement('span', { 
                            key: 'disconnected',
                            className: 'lem-status-disconnected' 
                        }, [
                            createElement('span', { key: 'dot', className: 'lem-status-dot' }),
                            __('No Event Selected', 'live-event-manager')
                        ])
                    )
                ]),
                
                createElement('div', { 
                    key: 'content',
                    className: 'lem-preview-content' 
                }, eventId ? 
                    createElement('div', { 
                        key: 'video',
                        className: 'lem-preview-video' 
                    }, [
                        createElement('div', { 
                            key: 'placeholder',
                            className: 'lem-video-placeholder',
                            style: { aspectRatio: aspectRatio }
                        }, [
                            createElement('div', { 
                                key: 'placeholder-content',
                                className: 'lem-placeholder-content' 
                            }, [
                                createElement('svg', { 
                                    key: 'icon',
                                    viewBox: '0 0 24 24', 
                                    fill: 'none', 
                                    stroke: 'currentColor', 
                                    strokeWidth: '2' 
                                }, [
                                    createElement('polygon', { 
                                        key: 'play',
                                        points: '5,3 19,12 5,21' 
                                    })
                                ]),
                                createElement('p', { key: 'text' }, __('Video Player Preview', 'live-event-manager'))
                            ])
                        ])
                    ]) : 
                    createElement('div', { 
                        key: 'no-event',
                        className: 'lem-no-event' 
                    }, [
                        createElement('p', { key: 'message' }, __('Please select an event from the sidebar to configure the video player.', 'live-event-manager'))
                    ])
                )
            ])
        ]);
    },
    
    save: function() {
        // This block is rendered on the server side
        return null;
    }
}); 