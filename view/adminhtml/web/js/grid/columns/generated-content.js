define([
    'Magento_Ui/js/grid/columns/column'
], function (Column) {
    'use strict';

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

    var order = [
        'subtitle',
        'features',
        'short_description',
        'description',
        'meta_title',
        'meta_keyword',
        'meta_keywords',
        'meta_description',
        'image_label',
        'small_image_label',
        'thumbnail_label'
    ];

    return Column.extend({
        defaults: {
            bodyTmpl: 'Nistruct_ContentAI/grid/cells/generated-content',
            contentAiMode: 'single'
        },

        getRows: function (row) {
            if (this.contentAiMode === 'bulk') {
                return this.getBulkRows(row);
            }

            return this.getSingleRows(row);
        },

        getSingleRows: function (row) {
            var value = row[this.index] || row.ai_description || '';
            var data = this.parseJson(value);
            var rows = [];

            if (!data) {
                return [{
                    label: 'Generated Content',
                    value: this.truncate(this.normalize(value), 300)
                }];
            }

            order.forEach(function (code) {
                var text = data[code];

                if (typeof text === 'undefined' || text === null || String(text).trim() === '') {
                    return;
                }

                rows.push({
                    label: labels[code] || code,
                    value: this.truncate(this.normalize(text), 170)
                });
            }, this);

            return rows.length ? rows : [{
                label: 'Generated Content',
                value: '-'
            }];
        },

        getBulkRows: function (row) {
            var data = this.parseJson(row[this.index] || '');
            var products = data && Array.isArray(data.products) ? data.products : [];
            var pending = 0;
            var applied = 0;
            var skus = [];

            products.forEach(function (product) {
                if (product.sku) {
                    skus.push(product.sku);
                }

                if (product.approval_status === 'applied') {
                    applied++;
                    return;
                }

                if (product.fields && Object.keys(product.fields).length) {
                    pending++;
                }
            });

            return [
                {label: 'Products', value: String(products.length)},
                {label: 'Pending', value: String(pending)},
                {label: 'Applied', value: String(applied)},
                {label: 'SKUs', value: skus.length ? skus.slice(0, 12).join(', ') : '-'}
            ];
        },

        parseJson: function (value) {
            if (typeof value !== 'string' || value.trim() === '') {
                return null;
            }

            try {
                return JSON.parse(value);
            } catch (e) {
                return null;
            }
        },

        normalize: function (value) {
            var wrapper = document.createElement('div');
            var text;

            wrapper.innerHTML = String(value)
                .replace(/<\/li>\s*<li>/gi, ' - ')
                .replace(/<li>/gi, '- ')
                .replace(/<br\s*\/?>/gi, ' - ');

            text = wrapper.textContent || wrapper.innerText || '';

            return text.replace(/\s+/g, ' ').trim();
        },

        truncate: function (value, limit) {
            value = String(value || '');

            if (value.length <= limit) {
                return value;
            }

            return value.substring(0, limit - 3) + '...';
        }
    });
});
