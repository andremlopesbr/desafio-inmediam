import 'react-credit-cards-2/dist/es/styles-compiled.css'

import { Badge } from '@inmediam/ui'
import { CircleCheck } from 'lucide-react'

import { currencyFormatter } from '@/utils/formatter'

interface PaymentCreditCard {
  card_holder_name: string
  card_last_four: string
  card_brand: string
}

interface Payment {
  amount_paid: number
  status: string
  paid_at: string
  credit_card: PaymentCreditCard
}

interface PaymentConcludedProps {
  payment: Payment
}

export function PaymentConcluded({ payment }: PaymentConcludedProps) {
  const { credit_card: creditCard } = payment

  return (
    <div className="w-full rounded-lg border border-border bg-card p-6 shadow-sm">
      <div className="mb-6 flex items-center gap-2">
        <CircleCheck className="h-5 w-5 text-emerald-500" />
        <h2 className="text-lg font-semibold text-foreground">
          Pagamento confirmado
        </h2>
      </div>

      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">Titular</span>
          <span className="font-medium text-foreground">
            {creditCard.card_holder_name}
          </span>
        </div>

        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">Cartão</span>
          <span className="font-medium text-foreground">
            {creditCard.card_brand} •••• {creditCard.card_last_four}
          </span>
        </div>

        <div className="flex items-center justify-between border-t border-border pt-3">
          <span className="text-sm text-muted-foreground">Valor pago</span>
          <span className="font-bold text-foreground">
            {currencyFormatter.format(payment.amount_paid)}
          </span>
        </div>

        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">Data</span>
          <span className="text-sm text-foreground">
            {new Date(payment.paid_at).toLocaleString('pt-BR')}
          </span>
        </div>

        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">Status</span>
          <Badge variant="success">Confirmado</Badge>
        </div>
      </div>
    </div>
  )
}
