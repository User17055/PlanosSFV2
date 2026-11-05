# Contratação online

O cliente escolhe um plano ou pacote na página pública e preenche seus dados e os do pet. O formulário exibe o produto e o preço selecionados. Não é necessário que a equipe cadastre o tutor antes.

Na tela Nova contratação, a opção O tutor completa pelo link dispensa tutor e pet previamente cadastrados. A equipe escolhe produto e unidade e gera um link de preenchimento com validade. Esse link abre diretamente o formulário do cliente, mantendo unidade e responsável comercial. Somente quando o tutor envia os dados surgem os registros e a proposta. A opção Site institucional preparada pela equipe usa o mesmo preenchimento automático. Eu preencho para o tutor conferir e Presencial continuam usando um cadastro existente.

Quando o mesmo link é aberto por uma pessoa autenticada da equipe, com acesso autorizado à unidade do link, ele apresenta o contrato e a área de aceite em visualização, sem criar cadastro ou registrar assinatura. O aceite fica desabilitado nessa visualização. Para um visitante sem login, o link continua levando ao formulário do tutor, que também permite ler o modelo do contrato antes de enviar os dados. Apenas modelos de ofertas disponíveis podem ser consultados publicamente; documentos assinados permanecem privados.

O envio cria tutor, pet, acesso e proposta em uma única transação. Se o pet não for elegível ou o produto ficar indisponível, não ficam registros parciais desse envio. Preço, canal, unidade e origem são determinados pelo servidor. O fluxo preserva a origem de links de divulgação da sessão.

Após salvar, o cliente segue para conferir os dados e o contrato da proposta. Quando a verificação do e-mail é obrigatória, a proposta é mantida na sessão e o link de verificação conduz à revisão do contrato. Esse cadastro não registra aceite, assinatura ou pagamento.

## Assinatura pelo link, sem senha

O link enviado ao tutor (`/convite/{token}`) abre sem login. A pessoa confirma o CPF do titular e chega ao contrato daquela proposta, onde revisa, assina e segue para o pagamento. Essa sessão alcança só o contrato do link: qualquer outra tela pede a senha. Se o titular ainda não tiver acesso, o sistema cria um com senha aleatória, e a pessoa define a própria em "Esqueci ou ainda não tenho senha", quando quiser ver o painel.

Cinco CPFs errados travam o link por 15 minutos. O link deixa de abrir o contrato depois da assinatura, ao vencer ou ao ser substituído. O contrato registra "Identificação: link pessoal enviado ao titular, com confirmação do CPF". Não abre se o titular não tiver e-mail válido ou se o e-mail já pertencer a outro acesso: nesses casos, a unidade corrige o cadastro.

Um CPF já cadastrado não cria outro tutor nem concede acesso ao cadastro existente. O cliente deve entrar com seu acesso ou usar o convite individual autorizado. O cadastro sem plano selecionado continua disponível para criação de acesso; não gera proposta de um produto que o cliente não escolheu.
