define([
    'jquery',
    'underscore',
    'oroui/js/tools/scroll-helper',
    'bootstrap'
], function($, _, scrollHelper) {
    'use strict';

    /**
     * Override for Dropdown constructor
     *  - added destroy method, which removes event handlers from <html /> node
     *
     * @param {HTMLElement} element
     * @constructor
     */
    function Dropdown(element) {
        var $el = $(element).on('click.dropdown.data-api', this.toggle);
        var globalHandlers = {
            'click.dropdown.data-api': function() {
                $el.parent().removeClass('open');
            }
        };
        $el.data('globalHandlers', globalHandlers);
        $('html').on(globalHandlers);
    }

    Dropdown.prototype = $.fn.dropdown.Constructor.prototype;

    $(document).off('click.dropdown.data-api', '[data-toggle=dropdown]', Dropdown.prototype.toggle);
    Dropdown.prototype.toggle = _.wrap(Dropdown.prototype.toggle, function(func, event) {
        var result = func.apply(this, _.rest(arguments));
        var href = $(this).attr('href');
        var selector = $(this).attr('data-target') || /#/.test(href) && href;
        var $parent = selector ? $(selector) : null;

        if (!$parent || $parent.length === 0) {
            $parent = $(this).parent();
        }
        if ($parent.hasClass('open')) {
            $parent.find('.dropdown-menu').trigger('shown.bs.dropdown');
        }

        return result;
    });
    $(document).on('click.dropdown.data-api', '[data-toggle=dropdown]', Dropdown.prototype.toggle);

    Dropdown.prototype.destroy = function() {
        var globalHandlers = this.data('globalHandlers');
        $('html').off(globalHandlers);
        this.removeData('dropdown');
        this.removeData('globalHandlers');
    };

    $.fn.dropdown = function(option) {
        return this.each(function() {
            var $this = $(this);
            var data = $this.data('dropdown');
            if (!data) {
                $this.data('dropdown', (data = new Dropdown(this)));
            }
            if (typeof option === 'string') {
                data[option].call($this);
            }
        });
    };

    $.fn.dropdown.Constructor = Dropdown;

    /**
     * fix endless loop
     * Based on https://github.com/Khan/bootstrap/commit/378ab557e24b861579d2ec4ce6f04b9ea995ab74
     * Updated to support two modals on page
     */
    $.fn.modal.Constructor.prototype.enforceFocus = function() {
        var that = this;
        $(document)
            .off('focusin.modal') // guard against infinite focus loop
            .on('focusin.modal', function safeSetFocus(e) {
                if (that.$element[0] !== e.target && !that.$element.has(e.target).length) {
                    $(document).off('focusin.modal');
                    that.$element.focus();
                    $(document).on('focusin.modal', safeSetFocus);
                }
            });
    };

    /**
     * This customization allows to define own click, render, show functions for Typeahead
     */
    var Typeahead;
    var origTypeahead = $.fn.typeahead.Constructor;
    var origFnTypeahead = $.fn.typeahead;

    Typeahead = function(element, options) {
        var opts = $.extend({}, $.fn.typeahead.defaults, options);
        this.click = opts.click || this.click;
        this.render = opts.render || this.render;
        this.show = opts.show || this.show;
        origTypeahead.apply(this, arguments);
    };

    Typeahead.prototype = origTypeahead.prototype;
    Typeahead.prototype.constructor = Typeahead;

    $.fn.typeahead = function(option) {
        return this.each(function() {
            var $this = $(this);
            var data = $this.data('typeahead');
            var options = typeof option === 'object' && option;
            if (!data) {
                $this.data('typeahead', (data = new Typeahead(this, options)));
            }
            if (typeof option === 'string') {
                data[option]();
            }
        });
    };

    $.fn.typeahead.defaults = origFnTypeahead.defaults;
    $.fn.typeahead.Constructor = Typeahead;
    $.fn.typeahead.noConflict = origFnTypeahead.noConflict;

    /**
     * Customization for Tooltip/Popover
     *  - propagate hide action to delegated tooltips/popovers
     *  - propagate destroy action to delegated tooltips/popovers
     */
    var Tooltip = $.fn.tooltip.Constructor;
    var Popover = $.fn.popover.Constructor;

    var delegateAction = function(method, action) {
        return function() {
            var type = this.type;
            // Tooltip/Popover delegates initialization to element -- propagate action them first
            if (this.options.selector) {
                this.$element.find(this.options.selector).each(function() {
                    if ($(this).data(type)) {
                        $(this).popover(action);
                    }
                });
            }
            // clear timeout if it exists
            if (this.timeout) {
                clearTimeout(this.timeout);
                delete this.timeout;
            }
            return method.apply(this, arguments);
        };
    };

    Popover.prototype.hide = delegateAction(Popover.prototype.hide, 'hide');
    Popover.prototype.destroy = delegateAction(Popover.prototype.destroy, 'destroy');
    Tooltip.prototype.hide = delegateAction(Tooltip.prototype.hide, 'hide');
    Tooltip.prototype.destroy = delegateAction(Tooltip.prototype.destroy, 'destroy');

    var originalApplyPlacement = Popover.prototype.applyPlacement;
    Popover.prototype.applyPlacement = function(coords, posId) {
        originalApplyPlacement.apply(this, arguments);

        /*
         * SCROLL support
         */
        var adjustmentLeft = scrollHelper.scrollIntoView(this.$tip[0]);

        /*
         * SHIFT support
         */
        if (posId === 'right' || posId === 'left') {
            var outerHeight = this.$tip.outerHeight();
            var visibleRect = scrollHelper.getVisibleRect(this.$tip[0]);
            var visibleHeight = visibleRect.bottom - visibleRect.top;
            if (visibleHeight < outerHeight - /* fixes floating pixel calculation */ 1) {
                // still doesn't match, decrease height and move into visible area
                this.$tip.css({
                    maxHeight: visibleHeight
                });
                this.$tip.css({
                    height: this.$tip.outerHeight()
                });
                var centerChange = (outerHeight - visibleHeight) / 2;

                this.$tip.css({
                    top: parseFloat(this.$tip.css('top')) + adjustmentLeft.vertical
                });
                this.$arrow.css({
                    top: 'calc(50% + ' + (centerChange - adjustmentLeft.vertical) + 'px)'
                });
            }
        }
    };
    var originalShow = Popover.prototype.show;
    Popover.prototype.show = function() {
        // remove adjustments made by applyPlacement
        if (this.$tip) {
            this.$tip.css({
                height: '',
                maxHeight: '',
                top: ''
            });
        }
        if (this.$arrow) {
            this.$arrow.css({
                top: ''
            });
        }

        originalShow.apply(this, arguments);
    };
});
