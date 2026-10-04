(function ($) {
  'use strict';

  function debounce(fn, wait) {
    var t;
    return function () {
      var ctx = this;
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(ctx, args);
      }, wait);
    };
  }

  function ensureContainers() {
    var $wrap = $('.atora-registration-extended');
    if (!$wrap.length) return null;

    var $input = $('#atora_city');
    var $country = $('#atora_country_code');
    var $box = $('#atora_city_suggestions');

    if (!$input.length || !$country.length || !$box.length) return null;
    return { $input: $input, $country: $country, $box: $box };
  }

  function renderSuggestions($box, items) {
    if (!items || !items.length) {
      $box.empty().hide();
      return;
    }

    var $ul = $('<ul class="atora-autocomplete-list" role="listbox" />');
    items.forEach(function (item) {
      var label = (item && item.label) ? String(item.label) : '';
      var value = (item && item.value) ? String(item.value) : label;
      if (!label) return;
      var $li = $('<li class="atora-autocomplete-item" role="option" />');
      $li.text(label);
      $li.data('value', value);
      $ul.append($li);
    });

    $box.empty().append($ul).show();
  }

  function fetchSuggestions(q, country, done) {
    if (!window.atoraReg || !atoraReg.ajax_url) {
      done([]);
      return;
    }

    $.ajax({
      url: atoraReg.ajax_url,
      method: 'GET',
      dataType: 'json',
      data: {
        action: 'atora_city_suggest',
        nonce: atoraReg.nonce || '',
        q: q,
        country: country
      }
    })
      .done(function (res) {
        var items = (res && res.success && Array.isArray(res.data)) ? res.data : [];
        done(items);
      })
      .fail(function () {
        done([]);
      });
  }

  $(function () {
    var ctx = ensureContainers();
    if (!ctx) return;

    var $input = ctx.$input;
    var $country = ctx.$country;
    var $box = ctx.$box;

    function hideBox() {
      $box.empty().hide();
    }

    $box.on('click', '.atora-autocomplete-item', function () {
      var value = $(this).data('value');
      if (value) {
        $input.val(String(value));
      }
      hideBox();
    });

    $(document).on('click', function (e) {
      if (!$(e.target).closest('#atora_city, #atora_city_suggestions').length) {
        hideBox();
      }
    });

    var doSuggest = debounce(function () {
      var q = String($input.val() || '').trim();
      var country = String($country.val() || '').trim();

      if (q.length < 2) {
        hideBox();
        return;
      }

      if (!country) {
        var msg = (window.atoraReg && atoraReg.i18n && atoraReg.i18n.select_country)
          ? atoraReg.i18n.select_country
          : 'Selecciona un país primero';
        renderSuggestions($box, [{ label: msg, value: '' }]);
        return;
      }

      $box.html('<div class="atora-autocomplete-loading">' +
        ((window.atoraReg && atoraReg.i18n && atoraReg.i18n.searching) ? atoraReg.i18n.searching : 'Buscando…') +
        '</div>').show();

      fetchSuggestions(q, country, function (items) {
        if (!items.length) {
          var noRes = (window.atoraReg && atoraReg.i18n && atoraReg.i18n.no_results)
            ? atoraReg.i18n.no_results
            : 'Sin resultados';
          renderSuggestions($box, [{ label: noRes, value: '' }]);
          return;
        }
        renderSuggestions($box, items);
      });
    }, 300);

    $input.on('input', doSuggest);
    $country.on('change', function () {
      hideBox();
      $input.trigger('input');
    });
  });
})(jQuery);

