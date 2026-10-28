$(document).ready(function () {
  $(".hamburger").on("click", function () {
    $(".hamburger").toggleClass("active");
    $(".header-nav").toggleClass("active");
    $("body").toggleClass("hidden-scroll");
  });
  $(".menu-item").on("click", function () {
    $(".hamburger").removeClass("active");
    $(".header-nav").removeClass("active");
    $("body").removeClass("hidden-scroll");
  });

  $('.whatsapp').mask('(00) 00000-0000');

  $('.link-anchor').on("click", function(e){
    e.preventDefault();

    $('html, body').animate({
      scrollTop: $($.attr(this, 'href')).offset().top
    }, 500);
  });

  $("#formFranqueado").validate({
		rules: {
			iNome: "required",
			iTelefone: "required",
			iCidade: "required",
			iMensagem: "required",
		},
		submitHandler: function(e) {
      var urlSend =  'https://luzpropria.com.br/api/sao-francisco.php';

      $.ajax({
        url: urlSend,
        method: 'POST',
        dataType: 'json',
        data: $('#formFranqueado').serialize(),
        beforeSend: function(){
          $('#formFranqueado .btn').prop('disabled', true);
        },
        success: function(data) {
          $('#formFranqueado')[0].reset();
          Swal.fire({
            title: "Contato enviado com sucesso!", 
            text: "Recebemos seu interesse em se tornar um franqueado, em breve entraremos em contato!", 
            icon: "success", timer: 3500, button: false
          });
          $('#formFranqueado .btn').prop('disabled', false);
        },
        error: function(data) {
          console.log(data);
          Swal.fire({
            title: "Erro no envio!", 
            text: "Infelizmente não foi possível enviar seu contato, verifique os campos e tente novamente!", 
            icon: "error", timer: 300500, button: false
          });
          $('#formFranqueado .btn').prop('disabled', false);
        },
      });

      return false;
		}
	});
});

const testimonialSwiper = new Swiper(".depoimentos-slider .swiper", {
  pagination: {
    el: ".swiper-pagination",
  },
});
