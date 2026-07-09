define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal',
    'uiRegistry'
], function ($, alert, modal, registry) {
    'use strict';

    var contentAI = {
        options: {
            toolbarButtonSelector: '.contentai-toolbar-generate',
            modalSelector: '#contentai-generate-modal',
            fieldListSelector: '.contentai-field-list',
            fieldStepSelector: '.contentai-field-step',
            previewStepSelector: '.contentai-preview-step',
            previewListSelector: '.contentai-preview-list',
            allowedGenerationFields: [
                'subtitle',
                'features',
                'short_description',
                'description',
                'meta_title',
                'meta_keyword',
                'meta_description',
                'image_label',
                'small_image_label',
                'thumbnail_label'
            ],
            fieldGroups: {
                general: {
                    label: 'General',
                    fields: ['subtitle']
                },
                content: {
                    label: 'Content',
                    fields: ['features', 'short_description', 'description']
                },
                seo: {
                    label: 'SEO',
                    fields: ['meta_title', 'meta_keyword', 'meta_description']
                },
                images: {
                    label: 'Images',
                    fields: ['image_label', 'small_image_label', 'thumbnail_label']
                }
            },
            fieldAliases: {
                subtitle: 'product_subtitle',
                features: 'tech_specs_features'
            },
            categoryGenerationFields: [
                'description',
                'meta_title',
                'meta_keywords',
                'meta_description'
            ],
            categoryFieldGroups: {
                content: {
                    label: 'Content',
                    fields: ['description']
                },
                seo: {
                    label: 'SEO',
                    fields: ['meta_title', 'meta_keywords', 'meta_description']
                }
            }
        },

        currentFields: [],
        generatedFields: {},
        currentEntityType: 'product',
        modalReady: false,

        initToolbarButton: function () {
            var self = this;

            if ($(this.options.toolbarButtonSelector).length) {
                return;
            }

            var $actions = $('.page-actions .page-actions-buttons').first();
            if (!$actions.length) {
                $actions = $('.page-actions').first();
            }

            if (!$actions.length) {
                return;
            }

            $('<button/>', {
                type: 'button',
                class: 'action-default scalable contentai-toolbar-generate',
                text: $.mage.__('Generate with AI')
            }).prependTo($actions).on('click', function () {
                self.openGenerateModal();
            });
        },

        openGenerateModal: function () {
            var self = this;
            var $modal = $(this.options.modalSelector);

            this.currentEntityType = this.detectEntityType();
            this.currentFields = this.collectEditableFields();
            this.generatedFields = {};
            this.renderFieldSelection();
            this.showFieldStep();

            if (!this.modalReady) {
                modal({
                    type: 'popup',
                    responsive: true,
                    title: this.getModalTitle(),
                    modalClass: 'nistruct-contentai-generate-modal',
                    buttons: [{
                        text: $.mage.__('Generate Preview'),
                        class: 'action-primary contentai-generate-preview',
                        click: function () {
                            self.generateSelectedFields();
                        }
                    }, {
                        text: $.mage.__('Apply Checked'),
                        class: 'action-primary contentai-apply-generated',
                        click: function () {
                            self.applyGeneratedFields();
                        }
                    }, {
                        text: $.mage.__('Cancel'),
                        class: 'action-secondary',
                        click: function () {
                            $modal.modal('closeModal');
                        }
                    }]
                }, $modal);
                this.modalReady = true;
            }

            $modal.modal('openModal');
            this.updateModalButtons(false);
        },

        collectEditableFields: function () {
            if (this.currentEntityType === 'category') {
                return this.collectCategoryEditableFields();
            }

            var self = this;
            var fields = [];
            var seen = {};

            $('[name^="product["]').each(function () {
                var $field = $(this);
                var code = self.getFieldCode($field.attr('name'));

                if (!self.isUsableField($field, code) || seen[code]) {
                    return;
                }

                seen[code] = true;
                fields.push({
                    code: code,
                    targetCode: code,
                    label: self.getFieldLabel($field, code),
                    value: self.getProductFieldCurrentValue(code) || self.getFieldValue($field)
                });
            });

            fields.sort(function (a, b) {
                return this.getAllowedGenerationFields().indexOf(a.code) -
                    this.getAllowedGenerationFields().indexOf(b.code);
            }.bind(this));

            fields = fields.filter(function (field) {
                return this.getAllowedGenerationFields().indexOf(field.code) !== -1;
            }.bind(this));

            this.getAllowedGenerationFields().forEach(function (code) {
                if (seen[code]) {
                    return;
                }

                var field = self.createVirtualField(code);
                if (field) {
                    fields.push(field);
                    seen[code] = true;
                }
            });

            return fields;
        },

        isUsableField: function ($field, code) {
            var tagName = ($field.prop('tagName') || '').toLowerCase();
            var type = ($field.attr('type') || '').toLowerCase();

            if (!code || this.getAllowedGenerationFields().indexOf(code) === -1) {
                return false;
            }
            if ($field.is(':disabled') || $field.is('[readonly]')) {
                return false;
            }
            if (type && ['hidden', 'file', 'checkbox', 'radio', 'button', 'submit'].indexOf(type) !== -1) {
                return false;
            }
            if (tagName === 'select') {
                return false;
            }
            if (tagName !== 'textarea' && tagName !== 'input') {
                return false;
            }

            return true;
        },

        createVirtualField: function (code) {
            var labels = {
                subtitle: 'Subtitle',
                features: 'Features',
                short_description: 'Short Description',
                description: 'Description',
                meta_title: 'Meta Title',
                meta_keyword: 'Meta Keywords',
                meta_keywords: 'Meta Keywords',
                meta_description: 'Meta Description',
                image_label: 'Base Image Label',
                small_image_label: 'Small Image Label',
                thumbnail_label: 'Thumbnail Label'
            };
            var targetCode = this.getTargetFieldCode(code);
            var $targetField = $('[name="product[' + targetCode + ']"]').first();

            return {
                code: code,
                targetCode: targetCode,
                label: labels[code] || code,
                value: this.getProductFieldCurrentValue(code) ||
                    ($targetField.length ? this.getFieldValue($targetField) : '')
            };
        },

        collectCategoryEditableFields: function () {
            var self = this;
            var fields = [];

            this.getAllowedGenerationFields().forEach(function (code) {
                fields.push({
                    code: code,
                    targetCode: code,
                    label: self.getDefaultFieldLabel(code),
                    value: self.getCategoryFieldCurrentValue(code)
                });
            });

            return fields;
        },

        getFieldCode: function (name) {
            var match = String(name || '').match(/^product\[([^\]]+)\]/);
            return match ? match[1] : '';
        },

        getFieldLabel: function ($field, code) {
            var label = $field.closest('.admin__field').find('label span').first().text();
            label = $.trim(label || '');

            if (!label) {
                label = code.replace(/_/g, ' ');
                label = label.charAt(0).toUpperCase() + label.slice(1);
            }

            return label;
        },

        detectEntityType: function () {
            if (registry.get('category_form.category_form_data_source') ||
                window.location.href.indexOf('/catalog/category/') !== -1 ||
                $('[name="category[name]"], [name="name"]').length && registry.get('index = name')) {
                return 'category';
            }

            return 'product';
        },

        getModalTitle: function () {
            return this.currentEntityType === 'category'
                ? $.mage.__('Generate Category Fields with AI')
                : $.mage.__('Generate Product Fields with AI');
        },

        getAllowedGenerationFields: function () {
            return this.currentEntityType === 'category'
                ? this.options.categoryGenerationFields
                : this.options.allowedGenerationFields;
        },

        getFieldGroups: function () {
            return this.currentEntityType === 'category'
                ? this.options.categoryFieldGroups
                : this.options.fieldGroups;
        },

        getFieldValue: function ($field) {
            var editorValue = this.getEditorFieldValue($field);

            if (editorValue !== '') {
                return editorValue;
            }

            return $field.val() || '';
        },

        getEditorFieldValue: function ($field) {
            var editor;
            var id = $field.attr('id');

            if (id && window.tinyMCE && typeof window.tinyMCE.get === 'function') {
                editor = window.tinyMCE.get(id);
                if (editor && typeof editor.getContent === 'function') {
                    return editor.getContent() || '';
                }
            }

            return '';
        },

        getProductFieldCurrentValue: function (code) {
            var targetCode = this.getTargetFieldCode(code);
            var $field = $('[name="product[' + targetCode + ']"]').first();
            var candidates = [];
            var component = registry.get('index = ' + targetCode);
            var provider = registry.get('product_form.product_form_data_source');

            if (['image_label', 'small_image_label', 'thumbnail_label'].indexOf(code) !== -1) {
                candidates.push(this.getImageLabelCurrentValue(code));
            }

            if ($field.length) {
                candidates.push(this.getFieldValue($field));
            }

            if (component) {
                if (typeof component.value === 'function') {
                    candidates.push(component.value());
                } else if (typeof component.value !== 'undefined') {
                    candidates.push(component.value);
                }
                if (typeof component.initialValue !== 'undefined') {
                    candidates.push(component.initialValue);
                }
            }

            if (provider && provider.data && provider.data.product &&
                typeof provider.data.product[targetCode] !== 'undefined') {
                candidates.push(provider.data.product[targetCode]);
            }

            for (var i = 0; i < candidates.length; i++) {
                if (this.isMeaningfulValue(candidates[i])) {
                    return String(candidates[i]);
                }
            }

            return '';
        },

        getImageLabelCurrentValue: function (code) {
            var role = this.getImageRoleByLabelCode(code);
            var provider = registry.get('product_form.product_form_data_source');
            var productData = provider && provider.data && provider.data.product ? provider.data.product : {};
            var images = this.getMediaGalleryImages(productData);
            var selectedFile = productData[role] || this.getUiComponentValue(role) || $('[name="product[' + role + ']"]').first().val();
            var matchedImage;
            var domValue;

            if (selectedFile && images.length) {
                matchedImage = images.filter(function (image) {
                    return image && image.file === selectedFile;
                })[0];

                if (matchedImage && this.isMeaningfulValue(matchedImage.label)) {
                    return String(matchedImage.label);
                }
            }

            matchedImage = images.filter(function (image) {
                var types = image && $.isArray(image.types) ? image.types : [];

                return types.indexOf(role) !== -1 || image && image[role];
            })[0];

            if (matchedImage && this.isMeaningfulValue(matchedImage.label)) {
                return String(matchedImage.label);
            }

            domValue = this.getImageLabelFromDom(role, selectedFile);
            return this.isMeaningfulValue(domValue) ? domValue : '';
        },

        getImageRoleByLabelCode: function (code) {
            return {
                image_label: 'image',
                small_image_label: 'small_image',
                thumbnail_label: 'thumbnail'
            }[code] || 'image';
        },

        getUiComponentValue: function (code) {
            var component = registry.get('index = ' + code);

            if (!component) {
                return '';
            }

            if (typeof component.value === 'function') {
                return component.value();
            }

            if (typeof component.value !== 'undefined') {
                return component.value;
            }

            return '';
        },

        getMediaGalleryImages: function (productData) {
            var gallery = productData.media_gallery || {};
            var images = gallery.images || productData['media_gallery[images]'] || [];

            if ($.isArray(images)) {
                return images;
            }

            if ($.isPlainObject(images)) {
                return $.map(images, function (image) {
                    return image;
                });
            }

            return [];
        },

        getImageLabelFromDom: function (role, selectedFile) {
            var $labelField;
            var $fileField;
            var roleSelector = '[name*="[types][' + role + ']"], [name*="[types][]"][value="' + role + '"], [data-role="' + role + '"]';
            var $roleField = $(roleSelector).filter(':checked').first();

            if ($roleField.length) {
                $labelField = $roleField.closest('tr, .admin__field-complex, .image, .file-row')
                    .find('[name*="[label]"]')
                    .first();

                if ($labelField.length) {
                    return this.getFieldValue($labelField);
                }
            }

            if (selectedFile) {
                $fileField = $('[name*="[file]"]').filter(function () {
                    return $(this).val() === selectedFile;
                }).first();

                if ($fileField.length) {
                    $labelField = $fileField.closest('tr, .admin__field-complex, .image, .file-row')
                        .find('[name*="[label]"]')
                        .first();

                    if ($labelField.length) {
                        return this.getFieldValue($labelField);
                    }
                }
            }

            return '';
        },

        isMeaningfulValue: function (value) {
            return typeof value !== 'undefined' && value !== null && String(value).trim() !== '';
        },

        renderFieldSelection: function () {
            var html = [];
            var groupedFields = this.groupFields(this.currentFields);

            if (!this.currentFields.length) {
                html.push('<p>' + $.mage.__('No editable fields were found.') + '</p>');
            }

            html.push(this.renderGroupTabs(groupedFields, 'contentai-field-tab', 'contentai-field-panel'));

            $.each(groupedFields, function (groupKey, group) {
                html.push(
                    '<div class="contentai-tab-panel contentai-field-panel ' + (group.active ? 'is-active' : '') + '" data-tab-panel="' +
                    this.escapeHtml(groupKey) + '">'
                );
                group.fields.forEach(function (field) {
                    html.push(
                        '<label class="contentai-field-option">' +
                        '<input type="checkbox" class="contentai-field-checkbox" value="' + this.escapeHtml(field.code) + '" checked> ' +
                        '<span class="contentai-field-label">' + this.escapeHtml(field.label) + '</span>' +
                        '</label>'
                    );
                }, this);
                html.push('</div>');
            }.bind(this));

            $(this.options.fieldListSelector).html(html.join(''));
            this.bindTabs('.contentai-field-tab', '.contentai-field-panel');
        },

        groupFields: function (fields) {
            var groups = {};
            var activeAssigned = false;

            $.each(this.getFieldGroups(), function (groupKey, groupConfig) {
                var groupFields = fields.filter(function (field) {
                    return groupConfig.fields.indexOf(field.code) !== -1;
                });

                if (!groupFields.length) {
                    return;
                }

                groups[groupKey] = {
                    label: groupConfig.label,
                    fields: groupFields,
                    active: !activeAssigned
                };
                activeAssigned = true;
            });

            return groups;
        },

        renderGroupTabs: function (groups, tabClass, panelClass) {
            var html = ['<div class="contentai-tabs">'];

            $.each(groups, function (groupKey, group) {
                html.push(
                    '<button type="button" class="contentai-tab ' + tabClass + ' ' + (group.active ? 'is-active' : '') +
                    '" data-tab-target="' + this.escapeHtml(groupKey) + '" data-tab-panel-class="' + this.escapeHtml(panelClass) + '">' +
                    this.escapeHtml(group.label) +
                    '</button>'
                );
            }.bind(this));

            html.push('</div>');

            return html.join('');
        },

        bindTabs: function (tabSelector, panelSelector) {
            $(tabSelector).off('click.contentaiTabs').on('click.contentaiTabs', function () {
                var $tab = $(this);
                var target = $tab.data('tab-target');

                $tab.closest('.contentai-modal-body').find(tabSelector).removeClass('is-active');
                $tab.closest('.contentai-modal-body').find(panelSelector).removeClass('is-active');
                $tab.addClass('is-active');
                $tab.closest('.contentai-modal-body')
                    .find(panelSelector + '[data-tab-panel="' + target + '"]')
                    .addClass('is-active');
            });
        },

        generateSelectedFields: function () {
            var self = this;
            var selected = this.getSelectedFields();

            if (!selected.length) {
                this.showGenerationError($.mage.__('Please select at least one field.'));
                return;
            }

            $.ajax({
                url: window.contentAIAjaxUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    form_key: FORM_KEY,
                    generate_fields: 1,
                    entity_type: this.currentEntityType,
                    sku: $("input[name='product[sku]']").val(),
                    category_id: this.getCategoryId(),
                    store: this.getCurrentStoreId(),
                    selected_fields: JSON.stringify(selected),
                    product_data: JSON.stringify(this.collectProductData()),
                    category_data: JSON.stringify(this.collectCategoryData())
                },
                success: function (response) {
                    if (response.error === false && response.data && response.data.fields) {
                        self.generatedFields = response.data.fields;
                        self.renderPreview(response.data.fields);
                        self.showPreviewStep();
                        self.updateModalButtons(true);
                    } else {
                        self.showGenerationError(response.data || $.mage.__('Could not generate content.'));
                    }
                },
                error: function (xhr, textStatus, errorThrown) {
                    self.showGenerationError(errorThrown || textStatus);
                }
            });
        },

        getSelectedFields: function () {
            var self = this;
            var selected = [];

            $('.contentai-field-checkbox:checked').each(function () {
                var code = $(this).val();
                var field = self.findField(code);

                if (field) {
                    selected.push(field);
                }
            });

            return selected;
        },

        collectProductData: function () {
            var self = this;
            var data = {};

            $('[name^="product["]').each(function () {
                var $field = $(this);
                var code = self.getFieldCode($field.attr('name'));
                var type = ($field.attr('type') || '').toLowerCase();
                var tagName = ($field.prop('tagName') || '').toLowerCase();
                var value;

                if (!code || type === 'hidden' || type === 'file' || type === 'button' || type === 'submit') {
                    return;
                }
                if ($field.is(':disabled')) {
                    return;
                }

                if (tagName === 'select') {
                    value = $field.find('option:selected').text();
                } else if (type === 'checkbox' || type === 'radio') {
                    if (!$field.is(':checked')) {
                        return;
                    }
                    value = $field.val();
                } else {
                    value = self.getFieldValue($field);
                }

                if (typeof value === 'undefined' || value === null || String(value).trim() === '') {
                    return;
                }

                data[code] = {
                    label: self.getFieldLabel($field, code),
                    value: value
                };
            });

            this.options.allowedGenerationFields.forEach(function (code) {
                var targetCode = self.getTargetFieldCode(code);
                var value = self.getProductFieldCurrentValue(code);

                if (!value || data[targetCode]) {
                    return;
                }

                data[targetCode] = {
                    label: self.getDefaultFieldLabel(code),
                    value: value
                };
            });

            data.sku = {
                label: 'SKU',
                value: $("input[name='product[sku]']").val() || ''
            };

            data.store_id = {
                label: 'Store ID',
                value: this.getCurrentStoreId()
            };

            return data;
        },

        collectCategoryData: function () {
            var self = this;
            var data = {};
            var provider = registry.get('category_form.category_form_data_source');
            var categoryData = provider && provider.data && provider.data.category ? provider.data.category : {};

            if (this.currentEntityType !== 'category') {
                return data;
            }

            $.each(categoryData, function (code, value) {
                if (!self.isMeaningfulValue(value) || $.isArray(value) || $.isPlainObject(value)) {
                    return;
                }

                data[code] = {
                    label: self.getDefaultFieldLabel(code),
                    value: value
                };
            });

            $('[name]').each(function () {
                var $field = $(this);
                var code = self.getCategoryFieldCode($field.attr('name'));
                var type = ($field.attr('type') || '').toLowerCase();
                var tagName = ($field.prop('tagName') || '').toLowerCase();
                var value;

                if (!code || type === 'hidden' || type === 'file' || type === 'button' || type === 'submit') {
                    return;
                }

                if (tagName === 'select') {
                    value = $field.find('option:selected').text();
                } else if (type === 'checkbox' || type === 'radio') {
                    if (!$field.is(':checked')) {
                        return;
                    }
                    value = $field.val();
                } else {
                    value = self.getFieldValue($field);
                }

                if (!self.isMeaningfulValue(value)) {
                    return;
                }

                data[code] = {
                    label: self.getFieldLabel($field, code),
                    value: value
                };
            });

            data.category_id = {
                label: 'Category ID',
                value: this.getCategoryId()
            };
            data.store_id = {
                label: 'Store ID',
                value: this.getCurrentStoreId()
            };

            return data;
        },

        getDefaultFieldLabel: function (code) {
            var labels = {
                subtitle: 'Subtitle',
                features: 'Features',
                short_description: 'Short Description',
                description: 'Description',
                meta_title: 'Meta Title',
                meta_keyword: 'Meta Keywords',
                meta_description: 'Meta Description',
                image_label: 'Base Image Label',
                small_image_label: 'Small Image Label',
                thumbnail_label: 'Thumbnail Label'
            };

            return labels[code] || code.replace(/_/g, ' ');
        },

        getCategoryFieldCode: function (name) {
            var stringName = String(name || '');
            var match = stringName.match(/^category\[([^\]]+)\]/);

            if (match) {
                return match[1];
            }

            if (this.getAllowedGenerationFields().indexOf(stringName) !== -1 ||
                ['name', 'url_key', 'is_active', 'include_in_menu'].indexOf(stringName) !== -1) {
                return stringName;
            }

            return '';
        },

        getCategoryId: function () {
            var provider = registry.get('category_form.category_form_data_source');
            var data = provider && provider.data && provider.data.category ? provider.data.category : {};
            var id = data.id || data.entity_id || $('[name="id"]').val();
            var match;

            if (!id) {
                match = String(window.location.href).match(/\/id\/([0-9]+)(?:\/|$)/);
                id = match ? match[1] : '';
            }

            return id || '';
        },

        getCategoryFieldCurrentValue: function (code) {
            var candidates = [];
            var provider = registry.get('category_form.category_form_data_source');
            var categoryData = provider && provider.data && provider.data.category ? provider.data.category : {};
            var $field = $('[name="category[' + code + ']"], [name="' + code + '"]').first();
            var component = registry.get('index = ' + code);

            if ($field.length) {
                candidates.push(this.getFieldValue($field));
            }
            if (component) {
                if (typeof component.value === 'function') {
                    candidates.push(component.value());
                } else if (typeof component.value !== 'undefined') {
                    candidates.push(component.value);
                }
                if (typeof component.initialValue !== 'undefined') {
                    candidates.push(component.initialValue);
                }
            }
            if (typeof categoryData[code] !== 'undefined') {
                candidates.push(categoryData[code]);
            }

            for (var i = 0; i < candidates.length; i++) {
                if (this.isMeaningfulValue(candidates[i])) {
                    return String(candidates[i]);
                }
            }

            return '';
        },

        getCurrentStoreId: function () {
            var storeId = this.extractStoreId(window.location.href);
            var selectors = [
                '[name="store"]',
                '[name="store_id"]',
                '[name="store_switcher"]',
                '#store_switcher',
                '.store-switcher select',
                '.store-switcher [data-role="store-switcher"]',
                '.store-switcher a.active',
                '.store-switcher .active a',
                '.admin__scope-old select',
                '.admin__scope-old a.active'
            ];

            if (storeId !== '0') {
                return storeId;
            }

            selectors.some(function (selector) {
                var $element = $(selector).first();
                var candidate;

                if (!$element.length) {
                    return false;
                }

                candidate = $element.val() || $element.attr('data-store-id') || $element.attr('href') || '';
                storeId = this.extractStoreId(candidate);

                return storeId !== '0';
            }.bind(this));

            return storeId;
        },

        extractStoreId: function (value) {
            var stringValue = String(value || '');
            var match = stringValue.match(/[?&]store=([0-9]+)/) ||
                stringValue.match(/\/store\/([0-9]+)(?:\/|$)/) ||
                stringValue.match(/\/store_id\/([0-9]+)(?:\/|$)/);

            if (match) {
                return match[1];
            }

            return /^[0-9]+$/.test(stringValue) ? stringValue : '0';
        },

        renderPreview: function (fields) {
            var self = this;
            var html = [];
            var previewFields = [];
            var groupedFields;

            $.each(fields, function (code) {
                var field = self.findField(code);
                if (field) {
                    previewFields.push(field);
                }
            });

            groupedFields = this.groupFields(previewFields);

            html.push(this.renderGroupTabs(groupedFields, 'contentai-preview-tab', 'contentai-preview-panel'));

            $.each(groupedFields, function (groupKey, group) {
                html.push(
                    '<div class="contentai-tab-panel contentai-preview-panel ' + (group.active ? 'is-active' : '') + '" data-tab-panel="' +
                    self.escapeHtml(groupKey) + '">'
                );
                group.fields.forEach(function (field) {
                    var value = fields[field.code] || '';
                    var currentValue = field.value || '';

                    html.push(
                        '<div class="contentai-preview-item is-selected" data-field-code="' + self.escapeHtml(field.code) + '">' +
                    '<label class="contentai-preview-title">' +
                        '<input type="checkbox" class="contentai-preview-checkbox" value="' + self.escapeHtml(field.code) + '" checked> ' +
                        self.escapeHtml(field.label) +
                        '</label>' +
                        '<div class="contentai-preview-compare">' +
                        '<div class="contentai-preview-side contentai-preview-before">' +
                        '<div class="contentai-preview-side-title">' + $.mage.__('Current Value') + '</div>' +
                        '<div class="contentai-preview-current">' + (currentValue ? self.escapeHtml(currentValue) : '<span>-</span>') + '</div>' +
                        '</div>' +
                        '<div class="contentai-preview-arrow">&rarr;</div>' +
                        '<div class="contentai-preview-side contentai-preview-after">' +
                        '<div class="contentai-preview-side-title">' + $.mage.__('Generated Value') + '</div>' +
                    '<textarea class="admin__control-textarea contentai-preview-value" rows="5">' +
                    self.escapeHtml(value) +
                    '</textarea>' +
                        '</div>' +
                        '</div>' +
                    '</div>'
                    );
                });
                html.push('</div>');
            });

            $(this.options.previewListSelector).html(html.join(''));
            this.bindTabs('.contentai-preview-tab', '.contentai-preview-panel');
            this.bindPreviewSelectionState();
        },

        bindPreviewSelectionState: function () {
            $('.contentai-preview-checkbox').off('change.contentaiPreviewState').on('change.contentaiPreviewState', function () {
                $(this)
                    .closest('.contentai-preview-item')
                    .toggleClass('is-selected', this.checked)
                    .toggleClass('is-skipped', !this.checked);
            });
        },

        applyGeneratedFields: function () {
            var self = this;
            var fields = {};

            $('.contentai-preview-checkbox:checked').each(function () {
                var code = $(this).val();
                var value = $(this)
                    .closest('.contentai-preview-item')
                    .find('.contentai-preview-value')
                    .val();

                fields[code] = value;
            });

            if ($.isEmptyObject(fields)) {
                this.showGenerationError($.mage.__('Please select at least one generated field to apply.'));
                return;
            }

            $.ajax({
                url: window.contentAIAjaxUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    form_key: FORM_KEY,
                    apply_fields: 1,
                    entity_type: this.currentEntityType,
                    sku: $("input[name='product[sku]']").val(),
                    category_id: this.getCategoryId(),
                    store: this.getCurrentStoreId(),
                    fields: JSON.stringify(fields)
                },
                success: function (response) {
                    if (response.error === false) {
                        $.each(fields, function (code, value) {
                            if (self.currentEntityType === 'category') {
                                self.setCategoryFieldValue(code, value);
                            } else {
                                self.setProductFieldValue(code, value);
                            }
                        });
                        $(self.options.modalSelector).modal('closeModal');
                        window.location.reload();
                    } else {
                        self.showGenerationError(response.data || $.mage.__('Could not apply generated content.'));
                    }
                },
                error: function (xhr, textStatus, errorThrown) {
                    self.showGenerationError(errorThrown || textStatus);
                }
            });
        },

        setProductFieldValue: function (code, value) {
            var targetCode = this.getTargetFieldCode(code);
            var $field = $('[name="product[' + targetCode + ']"]').first();

            this.setProviderFieldValue(targetCode, value);
            this.setUiComponentValue(targetCode, value);

            if (!$field.length) {
                this.openProductSections();
                $field = $('[name="product[' + targetCode + ']"]').first();
            }

            if (!$field.length) {
                return;
            }

            $field.val(value).trigger('change');
            this.updateEditorBody($field, value);
        },

        getTargetFieldCode: function (code) {
            if (this.currentEntityType === 'category') {
                return code;
            }

            return this.options.fieldAliases[code] || code;
        },

        setCategoryFieldValue: function (code, value) {
            var $field = $('[name="category[' + code + ']"], [name="' + code + '"]').first();
            var provider = registry.get('category_form.category_form_data_source');

            if (provider) {
                provider.set('data.category.' + code, value);
                if (provider.data && provider.data.category) {
                    provider.data.category[code] = value;
                }
                if (typeof provider.trigger === 'function') {
                    provider.trigger('data.category.' + code, value);
                }
            }

            this.setUiComponentValue(code, value);

            if (!$field.length) {
                this.openProductSections();
                $field = $('[name="category[' + code + ']"], [name="' + code + '"]').first();
            }

            if ($field.length) {
                $field.val(value).trigger('change');
                this.updateEditorBody($field, value);
            }
        },

        setProviderFieldValue: function (code, value) {
            var provider = registry.get('product_form.product_form_data_source');

            if (!provider) {
                return;
            }

            provider.set('data.product.' + code, value);

            if (provider.data && provider.data.product) {
                provider.data.product[code] = value;
            }

            if (typeof provider.trigger === 'function') {
                provider.trigger('data.product.' + code, value);
            }
        },

        setUiComponentValue: function (code, value) {
            registry.get('index = ' + code, function (component) {
                if (!component) {
                    return;
                }

                if (typeof component.value === 'function') {
                    component.value(value);
                    return;
                }

                if (typeof component.set === 'function') {
                    component.set('value', value);
                }
            });
        },

        openProductSections: function () {
            $('[data-index], .admin__collapsible-block-wrapper').each(function () {
                var $section = $(this);
                var $title = $section.find('[data-role="title"]').first();

                if ($title.length && !$section.hasClass('_show') && !$section.hasClass('_active')) {
                    $title.trigger('click');
                }
            });
        },

        updateEditorBody: function ($field, value) {
            var $container = $field.closest('.admin__field');

            $container.find('iframe').each(function () {
                try {
                    $(this).contents().find('body').html(value).trigger('change');
                } catch (e) {
                    return true;
                }
            });
        },

        showFieldStep: function () {
            $(this.options.fieldStepSelector).show();
            $(this.options.previewStepSelector).hide();
        },

        showPreviewStep: function () {
            $(this.options.fieldStepSelector).hide();
            $(this.options.previewStepSelector).show();
        },

        updateModalButtons: function (hasPreview) {
            $('.contentai-generate-preview').toggle(!hasPreview);
            $('.contentai-apply-generated').toggle(hasPreview);
        },

        findField: function (code) {
            var result = null;

            this.currentFields.some(function (field) {
                if (field.code === code) {
                    result = field;
                    return true;
                }
                return false;
            });

            return result;
        },

        showGenerationError: function (error) {
            alert({
                title: $.mage.__('Generation Error'),
                content: error || $.mage.__('Could not generate content. Please check ContentAI logs.')
            });
        },

        escapeHtml: function (value) {
            return $('<div/>').text(value || '').html();
        }
    };

    return contentAI;
});
