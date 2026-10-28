<?php
return [
    'required'=>'O campo :attribute é obrigatório.', 'required_with'=>'Informe :attribute ao preencher :values.',
    'required_if'=>'O campo :attribute é obrigatório nesta situação.', 'email'=>'Informe um e-mail válido em :attribute.',
    'accepted'=>'Você precisa aceitar as condições para continuar.', 'confirmed'=>'A confirmação de :attribute não confere.',
    'unique'=>'Este :attribute já está cadastrado. Solicite uma revisão para evitar duplicidade.',
    'exists'=>'O registro selecionado em :attribute não está disponível.', 'in'=>'Selecione uma opção válida em :attribute.',
    'string'=>'Informe um texto em :attribute.', 'integer'=>'Informe um número inteiro em :attribute.',
    'digits'=>'O campo :attribute deve ter :digits dígitos.', 'regex'=>'O formato de :attribute é inválido.',
    'date'=>'Informe uma data válida em :attribute.', 'before_or_equal'=>':attribute deve ser anterior ou igual a :date.',
    'after'=>':attribute deve ser posterior a :date.', 'after_or_equal'=>':attribute deve ser posterior ou igual a :date.',
    'array'=>'O campo :attribute deve conter uma lista.', 'boolean'=>'Informe uma opção válida em :attribute.',
    'file'=>'Selecione um arquivo válido em :attribute.', 'mimetypes'=>'O arquivo em :attribute precisa estar no formato :values.',
    'min'=>['string'=>':attribute deve ter pelo menos :min caracteres.','numeric'=>':attribute deve ser pelo menos :min.','array'=>'Inclua pelo menos :min item em :attribute.'],
    'max'=>['string'=>':attribute deve ter no máximo :max caracteres.','numeric'=>':attribute deve ser no máximo :max.','file'=>'O arquivo deve ter no máximo :max KB.','array'=>'Inclua no máximo :max itens em :attribute.'],
    'attributes'=>['name'=>'nome','document'=>'CPF','email'=>'e-mail','phone'=>'telefone','address'=>'endereço','password'=>'senha','pet_name'=>'nome do pet','pet_species'=>'espécie','pet_birth_date'=>'nascimento do pet','accepted'=>'aceite','signature_data'=>'assinatura','reason'=>'motivo','signed_at'=>'data da assinatura','reference'=>'referência','amount_cents'=>'valor em centavos'],
];
