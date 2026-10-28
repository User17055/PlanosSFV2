$(document).ready(function(){

	if($(document).scrollTop() > 0){
		$('header').addClass('active');
	}else{
		$('header').removeClass('active');
	}

	
	$(window).scroll(function() {
		if($(window).width() > 978){
			if($(document).scrollTop() > 0){
				$('header').addClass('active');
			}else{
				$('header').removeClass('active');
			}
		}
	});



	$('.btn-menu').on('click', function(){
		$('header').toggleClass('open');
	});

	$('header .box-header nav ul li a').on('click', function(){
		if(mobile()){
			$('header').removeClass('open');
		}
	});

	$('.interna .box-servicos').slick({
		dots: false,
		arrows: true,
		infinite: true,
		speed: 300,
		prevArrow: '<div class="prev-arrow"><i class="fa fa-angle-left" aria-hidden="true"></i><i class="fa fa-angle-left" aria-hidden="true"></i></div>',
		nextArrow: '<div class="next-arrow"><i class="fa fa-angle-right" aria-hidden="true"></i><i class="fa fa-angle-right" aria-hidden="true"></i></div>',
		centerMode: true,
		adaptiveHeight: true,
		slidesToShow: 3,
		slidesToScroll: 1,
		responsive: [
		{
			breakpoint: 978,
			settings: {
				infinite: false,
				centerMode: false,
				slidesToShow: 2
			}
		},
		{
			breakpoint: 500,
			settings: {
				infinite: false,
				centerMode: false,
				slidesToShow: 1
			}
		}
		]
	});

	$('.interna .box-fotos').slick({
		dots: false,
		arrows: true,
		infinite: true,
		prevArrow: '<div class="prev-arrow"><i class="fa fa-angle-left" aria-hidden="true"></i><i class="fa fa-angle-left" aria-hidden="true"></i></div>',
		nextArrow: '<div class="next-arrow"><i class="fa fa-angle-right" aria-hidden="true"></i><i class="fa fa-angle-right" aria-hidden="true"></i></div>',
		speed: 300,
		adaptiveHeight: true,
		centerMode: true,
		slidesToShow: 3,
		slidesToScroll: 1,
		responsive: [
		{
			breakpoint: 978,
			settings: {
				infinite: false,
				centerMode: false,
				arrows: true,
				slidesToShow: 2
			}
		},
		{
			breakpoint: 500,
			settings: {
				infinite: false,
				arrows: true,
				centerMode: false,
				slidesToShow: 1
			}
		}
		]
	});


	setTimeout(function() {

		AOS.init();

	}, 600);


	setTimeout(function() {
		$('body').css({
			'opacity' : '1',
			'transition': '1s'
		})
	}, 100);



	setTimeout(function() {
		$('.loading').fadeOut();
	},1800);

	function mobile(){
		if($(window).width() < 978)
			return true;

		return false;
	}
	$('.tel').mask('(00) 00000-0000');
	$("#formContato").validate({
		rules: {
			iNome: "required",
			iTelefone: "required",
			iCidade: "required",
			iMensagem: "required",
		},
		submitHandler: function(e) {
		  	var urlSend =  'https://luzpropria.com.br/api/sao-francisco.php';

			if (grecaptcha.getResponse() == '') {
				$('#captcha iframe').addClass('error');
				$('#captcha').append('<label class="error">Verificação obrigatória</label>');
			} else {
	
				$('#captcha').find('.error').remove();
				$('#captcha iframe').removeClass('error');

				$.ajax({
					url: urlSend,
					method: 'POST',
					dataType: 'json',
					data: $('#formContato').serialize(),
					beforeSend: function(){
						$('#btn-contato').prop('disabled', true);
					},
					success: function(data) {
						$('#formContato')[0].reset();
						$('#formContato .btn').prop('disabled', false);
						Swal.fire({
						  title: "Contato enviado com sucesso!", 
						  text: "Recebemos sua mensagem, em breve entraremos em contato!", 
						  icon: "success",
						});
					  },
					  error: function(data) {
						console.log(data);
						$('#formContato .btn').prop('disabled', false);
						Swal.fire({
						  title: "Erro no envio!", 
						  text: "Infelizmente não foi possível enviar seu contato, verifique os campos e tente novamente!", 
						  icon: "error",
						});
					  },
				});
			}

		  	return false;
		}
	});
});


