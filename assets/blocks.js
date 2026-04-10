/**
 * Live Event Manager - Gutenberg Blocks
 */

(function() {
    'use strict';

    // Block categories
    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { addFilter } = wp.hooks;
    const { createElement } = wp.element;

    // Filter to hide event ticket block when not editing event post type
    addFilter('editor.BlockListBlock', 'live-event-manager/restrict-event-ticket-block', function(BlockListBlock, props) {
        // Check if props exists and has a name property
        if (props && props.name === 'lem/event-ticket') {
            // Get current post type
            const postType = wp.data.select('core/editor').getCurrentPostType();
            
            // If not editing an event post type, don't render the block
            if (postType !== 'lem_event') {
                return null;
            }
        }
        
        return BlockListBlock;
    });

    // Filter to hide event ticket block from inserter when not editing event post type
    addFilter('editor.BlockTypes', 'live-event-manager/restrict-event-ticket-inserter', function(blockTypes) {
        // Check if blockTypes exists and is an array
        if (!blockTypes || !Array.isArray(blockTypes)) {
            return blockTypes;
        }
        
        // Get current post type
        const postType = wp.data.select('core/editor').getCurrentPostType();
        
        // If not editing an event post type, filter out the event ticket block
        if (postType !== 'lem_event') {
            return blockTypes.filter(blockType => blockType && blockType.name !== 'lem/event-ticket');
        }
        
        return blockTypes;
    });

    // Register Smart Event Ticket Block
    registerBlockType('lem/event-ticket', {
        title: __('Event Ticket', 'live-event-manager'),
        description: __('Smart ticket block that handles both free and paid events automatically.', 'live-event-manager'),
        category: 'widgets',
        icon: 'tickets-alt',
        keywords: [
            __('ticket', 'live-event-manager'),
            __('event', 'live-event-manager'),
            __('payment', 'live-event-manager'),
            __('stripe', 'live-event-manager'),
            __('free', 'live-event-manager')
        ],
        attributes: {
            buttonText: {
                type: 'string',
                default: 'Get Access'
            },
            emailPlaceholder: {
                type: 'string',
                default: 'Enter your email address'
            },
            showPrice: {
                type: 'boolean',
                default: true
            },
            theme: {
                type: 'string',
                default: 'dark'
            },
            size: {
                type: 'string',
                default: 'large'
            },
            showEventDetails: {
                type: 'boolean',
                default: true
            }
        },
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { buttonText, emailPlaceholder, showPrice, theme, size, showEventDetails } = attributes;

            return createElement('div', {
                className: 'lem-block-editor lem-event-ticket-editor'
            }, [
                createElement('div', {
                    key: 'header',
                    className: 'lem-block-header'
                }, [
                    createElement('h3', {
                        key: 'title'
                    }, __('Event Ticket', 'live-event-manager')),
                    createElement('p', {
                        key: 'description'
                    }, __('Smart ticket block that automatically handles free and paid events.', 'live-event-manager'))
                ]),

                createElement('div', {
                    key: 'controls',
                    className: 'lem-block-controls'
                }, [
                    // Button Text
                    createElement('div', {
                        key: 'button-text',
                        className: 'lem-control-group'
                    }, [
                        createElement('label', {
                            key: 'label'
                        }, __('Button Text:', 'live-event-manager')),
                        createElement('input', {
                            key: 'input',
                            type: 'text',
                            value: buttonText,
                            onChange: function(e) {
                                setAttributes({ buttonText: e.target.value });
                            },
                            placeholder: __('Get Access', 'live-event-manager')
                        })
                    ]),

                    // Email Placeholder
                    createElement('div', {
                        key: 'email-placeholder',
                        className: 'lem-control-group'
                    }, [
                        createElement('label', {
                            key: 'label'
                        }, __('Email Placeholder:', 'live-event-manager')),
                        createElement('input', {
                            key: 'input',
                            type: 'text',
                            value: emailPlaceholder,
                            onChange: function(e) {
                                setAttributes({ emailPlaceholder: e.target.value });
                            },
                            placeholder: __('Enter your email address', 'live-event-manager')
                        })
                    ]),

                    // Show Price Toggle
                    createElement('div', {
                        key: 'show-price',
                        className: 'lem-control-group'
                    }, [
                        createElement('label', {
                            key: 'label'
                        }, [
                            createElement('input', {
                                key: 'checkbox',
                                type: 'checkbox',
                                checked: showPrice,
                                onChange: function(e) {
                                    setAttributes({ showPrice: e.target.checked });
                                }
                            }),
                            ' ',
                            __('Show price', 'live-event-manager')
                        ])
                    ]),

                    // Theme Selection
                    createElement('div', {
                        key: 'theme-select',
                        className: 'lem-control-group'
                    }, [
                        createElement('label', {
                            key: 'label'
                        }, __('Theme:', 'live-event-manager')),
                        createElement('select', {
                            key: 'select',
                            value: theme,
                            onChange: function(e) {
                                setAttributes({ theme: e.target.value });
                            }
                        }, [
                            createElement('option', {
                                key: 'dark',
                                value: 'dark'
                            }, __('Dark', 'live-event-manager')),
                            createElement('option', {
                                key: 'light',
                                value: 'light'
                            }, __('Light', 'live-event-manager')),
                            createElement('option', {
                                key: 'gradient',
                                value: 'gradient'
                            }, __('Gradient', 'live-event-manager'))
                        ])
                    ]),

                    // Size Selection
                    createElement('div', {
                        key: 'size-select',
                        className: 'lem-control-group'
                    }, [
                        createElement('label', {
                            key: 'label'
                        }, __('Size:', 'live-event-manager')),
                        createElement('select', {
                            key: 'select',
                            value: size,
                            onChange: function(e) {
                                setAttributes({ size: e.target.value });
                            }
                        }, [
                            createElement('option', {
                                key: 'small',
                                value: 'small'
                            }, __('Small', 'live-event-manager')),
                            createElement('option', {
                                key: 'medium',
                                value: 'medium'
                            }, __('Medium', 'live-event-manager')),
                            createElement('option', {
                                key: 'large',
                                value: 'large'
                            }, __('Large', 'live-event-manager'))
                        ])
                    ]),

                    // Show Event Details Toggle
                    createElement('div', {
                        key: 'show-details',
                        className: 'lem-control-group'
                    }, [
                        createElement('label', {
                            key: 'label'
                        }, [
                            createElement('input', {
                                key: 'checkbox',
                                type: 'checkbox',
                                checked: showEventDetails,
                                onChange: function(e) {
                                    setAttributes({ showEventDetails: e.target.checked });
                                }
                            }),
                            ' ',
                            __('Show event details', 'live-event-manager')
                        ])
                    ])
                ]),

                // Preview
                createElement('div', {
                    key: 'preview',
                    className: 'lem-block-preview'
                }, [
                    createElement('h4', {
                        key: 'preview-title'
                    }, __('Preview:', 'live-event-manager')),
                    createElement('div', {
                        key: 'preview-content',
                        className: 'lem-preview-container'
                    }, [
                        showEventDetails && createElement('div', {
                            key: 'event-info',
                            className: 'lem-preview-event-info'
                        }, [
                            createElement('h5', {
                                key: 'event-title'
                            }, __('Event Title', 'live-event-manager')),
                            createElement('p', {
                                key: 'event-date'
                            }, __('Event Date', 'live-event-manager')),
                            showPrice && createElement('div', {
                                key: 'event-price',
                                className: 'lem-preview-price'
                            }, __('Price Display', 'live-event-manager'))
                        ]),
                        createElement('div', {
                            key: 'email-input',
                            className: 'lem-preview-email-input'
                        }, [
                            createElement('input', {
                                key: 'input',
                                type: 'email',
                                placeholder: emailPlaceholder,
                                disabled: true
                            })
                        ]),
                        createElement('div', {
                            key: 'preview-button',
                            className: 'lem-preview-button'
                        }, buttonText)
                    ])
                ])
            ]);
        },
        save: function() {
            // Dynamic block - rendered on server
            return null;
        }
    });

})(); 