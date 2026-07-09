define([
    'jquery',
    'Nistruct_ContentAI/js/model/content-ai'
], function ($, contentAIModel) {
    'use strict';

    $.widget('mage.contentAigenerateWidget', {

        _create: function () {
            contentAIModel.initToolbarButton();
        }
    });

    return $.mage.contentAigenerateWidget;
});
