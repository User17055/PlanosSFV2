$(function() {

    var ul = $('#uploads');

    $('#drop a').click(function() {
        // Simulate a click on the file input button
        // to show the file browser dialog
        $(this).parent().find('input').click();
    });

    // Initialize the jQuery File Upload plugin
    $('#upload').fileupload({

        // This element will accept file drag/drop uploading
        dropZone: $('#drop'),

        // This function is called when a file is added to the queue;
        // either via the browse button, or via drag/drop:
        add: function(e, data) {

            var total = (ul.find('li').length - 2);
            var tpl = $('<li class="col-md-2">' +
                '<div class="lib-panel">' +
                '<img class="lib-img-show" src="">' +
                '<div class="loading"></div>' +
                '</div>' +
                '</li>');

            var reader = new FileReader();
            reader.onload = function(e) {
                tpl.find('img').attr('src', e.target.result);
                tpl.find('.loading').css('width', 100);
            };

            reader.readAsDataURL(data.files[0]);
            if (data.files[0].type.slice(0, 5) !== 'image') {
                return;
            }

            // Add the HTML to the UL element
            if (total >= 0) {
                ul.find('li').eq(total).after(tpl);
            } else {
                tpl.prependTo(ul);
            }

            data.context = tpl;

            // Initialize the knob plugin
            tpl.find('input').knob();

            // Listen for clicks on the cancel icon
            tpl.find('span').click(function() {

                if (tpl.hasClass('working')) {
                    jqXHR.abort();
                }

                tpl.fadeOut(function() {
                    tpl.remove();
                });

            });

            // Automatically upload the file once it is added to the queue
            var jqXHR = data.submit();
        },

        progress: function(e, data) {
            // Calculate the completion percentage of the upload
            var progress = parseInt(data.loaded / data.total * 100, 10);
            data.context.find('.loading').animate({
                'width': (100 - progress)
            });
        },

        fail: function(e, data) {
            var error = data.jqXHR.responseJSON;
            // Remove imagem
            notif({
                msg: "<b>Ops!</b> " + error.message,
                type: "error",
                opacity: 0.8,
                autohide: false,
                multiline: true,
            });
            data.context.remove();
            //setTimeout(function(){ window.location.reload(); }, 1000);
        },
        done: function(e, data) {
            var result = data.result;
            var url_imagem = ul.find('input[name="url_imagem"]').val();
            if (result.status) {
                var buttons = '' +
                            (result.padrao == 1 ? '<i class="icon-default glyphicon glyphicon-star"></i>' : '') +
                            '<div class="btn-actions">' +
                                '<form class="btn-group">' +
                                    '<a href="javascript:void(0);" type="button" class="btn btn-default btn-move"><i class="glyphicon glyphicon-move text-green"></i></a>' +
                                '</form>' +
                                '<form class="btn-group" action="' + urls.def.replace('PLACEHOLDER', result.id) + '" method="post">' +
                                    '<input type="hidden" name="_method" value="put">' +
                                    '<button type="submit" class="btn btn-default btn-star"><i class="glyphicon ' + (result.padrao == 1 ? 'glyphicon-star' : 'glyphicon-star-empty') + ' text-yellow"></i></button>' +
                                '</form>' +
                                '<form class="btn-group" action="' + urls.del.replace('PLACEHOLDER', result.id) + '" method="post">' +
                                    '<button type="submit" class="btn btn-default btn-delete"><i class="glyphicon glyphicon-trash text-red"></i></button>' +
                                    '<input type="hidden" name="_method" value="DELETE">' +
                                '</form>' +
                            '</div>' +
                            '<img class="lib-img-show" src="/upload/' + url_imagem + '/' + result.imagem + '?w=165&h=165&fit=crop">';

                data.context
                    .attr('id', 'order-'+result.id)
                    .attr('data-id', result.id)
                    .find('.lib-panel').html(buttons);
                // console.log(data.result);
                // data.context.find('.lib-img-show').attr('src', '/img/produtos/' + data.result.image + '?w=165&h=165&fit=crop');
            }
        },
    });


    // Prevent the default action when a file is dropped on the window
    $(document).on('drop dragover', function(e) {
        e.preventDefault();
    });

    // Helper function that formats the file sizes
    function formatFileSize(bytes) {
        if (typeof bytes !== 'number') {
            return '';
        }

        if (bytes >= 1000000000) {
            return (bytes / 1000000000).toFixed(2) + ' GB';
        }

        if (bytes >= 1000000) {
            return (bytes / 1000000).toFixed(2) + ' MB';
        }

        return (bytes / 1000).toFixed(2) + ' KB';
    }

});
