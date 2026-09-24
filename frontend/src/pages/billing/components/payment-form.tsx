import 'react-credit-cards-2/dist/es/styles-compiled.css'

import { zodResolver } from '@hookform/resolvers/zod'
import { Button, Input, Label } from '@inmediam/ui'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { useState } from 'react'
import Cards, { Focused } from 'react-credit-cards-2'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'

import { api } from '@/lib/api'

const paymentSchema = z.object({
  cardNumber: z
    .string()
    .transform((v) => v.replace(/[\s-]/g, ''))
    .pipe(z.string().regex(/^\d{13,19}$/, 'Número inválido')),
  holderName: z.string().min(1, 'Nome do titular é obrigatório'),
  expiryDate: z
    .string()
    .regex(/^(0[1-9]|1[0-2])\/\d{2}$/, 'Formato inválido (MM/AA)')
    .refine((val) => {
      const [month, year] = val.split('/')
      const date = new Date(2000 + parseInt(year), parseInt(month), 0)
      const today = new Date()
      today.setHours(0, 0, 0, 0)
      return date >= today
    }, 'Cartão expirado'),
  cvv: z.string().regex(/^\d{3,4}$/, 'CVV deve ter 3 ou 4 dígitos'),
})

type PaymentFormData = z.infer<typeof paymentSchema>

interface PaymentFormProps {
  billingId: string
  amount: number
}

export function PaymentForm({ billingId }: PaymentFormProps) {
  const queryClient = useQueryClient()
  const [focused, setFocused] = useState<Focused>('')

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useForm<PaymentFormData>({
    resolver: zodResolver(paymentSchema),
  })

  const watchedValues = {
    cardNumber: watch('cardNumber', ''),
    holderName: watch('holderName', ''),
    expiryDate: watch('expiryDate', ''),
    cvv: watch('cvv', ''),
  }

  const { mutateAsync: submitPayment, isPending } = useMutation({
    mutationFn: (data: PaymentFormData) =>
      api.post(`/billing/${billingId}/pay`, {
        card_number: data.cardNumber,
        card_holder_name: data.holderName,
        expiry_date: data.expiryDate,
        cvv: data.cvv,
      }),
    onSuccess: () => {
      toast.success('Pagamento realizado com sucesso!')
      queryClient.invalidateQueries({ queryKey: ['billing', billingId] })
    },
    onError: (error) => {
      const err = error as {
        response?: { data?: { error?: string; message?: string } }
      }
      const message =
        err.response?.data?.error ||
        err.response?.data?.message ||
        'Erro ao processar pagamento.'
      toast.error(message)
    },
  })

  function handlePayment(data: PaymentFormData) {
    submitPayment(data)
  }

  return (
    <div className="w-full rounded-lg border border-border bg-card p-6 shadow-sm">
      <h2 className="mb-6 text-lg font-semibold text-foreground">
        Dados do cartão
      </h2>

      <div className="mb-6">
        <Cards
          number={watchedValues.cardNumber}
          name={watchedValues.holderName}
          expiry={watchedValues.expiryDate}
          cvc={watchedValues.cvv}
          focused={focused}
        />
      </div>

      <form onSubmit={handleSubmit(handlePayment)} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="cardNumber">Número do cartão</Label>
          <Input
            id="cardNumber"
            placeholder="0000 0000 0000 0000"
            {...register('cardNumber', {
              onChange: (e) => {
                let value = e.target.value.replace(/\D/g, '')
                if (value.length > 19) value = value.slice(0, 19)
                const formatted = value.replace(/(\d{4})(?=\d)/g, '$1 ').trim()
                e.target.value = formatted
                setValue('cardNumber', formatted)
              },
            })}
            onFocus={() => setFocused('number')}
          />
          {errors.cardNumber && (
            <p className="text-sm text-error-500">
              {errors.cardNumber.message}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="holderName">Nome do titular</Label>
          <Input
            id="holderName"
            placeholder="JOÃO M A SILVA"
            {...register('holderName')}
            onFocus={() => setFocused('name')}
          />
          {errors.holderName && (
            <p className="text-sm text-error-500">
              {errors.holderName.message}
            </p>
          )}
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="expiryDate">Validade</Label>
            <Input
              id="expiryDate"
              placeholder="MM/AA"
              {...register('expiryDate', {
                onChange: (e) => {
                  let value = e.target.value.replace(/\D/g, '')
                  if (value.length > 4) value = value.slice(0, 4)
                  if (value.length >= 3) {
                    value = `${value.slice(0, 2)}/${value.slice(2)}`
                  }
                  e.target.value = value
                  setValue('expiryDate', value)
                },
              })}
              onFocus={() => setFocused('expiry')}
            />
            {errors.expiryDate && (
              <p className="text-sm text-error-500">
                {errors.expiryDate.message}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="cvv">CVV</Label>
            <Input
              id="cvv"
              placeholder="123"
              {...register('cvv', {
                onChange: (e) => {
                  let value = e.target.value.replace(/\D/g, '')
                  if (value.length > 4) value = value.slice(0, 4)
                  e.target.value = value
                  setValue('cvv', value)
                },
              })}
              onFocus={() => setFocused('cvc')}
            />
            {errors.cvv && (
              <p className="text-sm text-error-500">{errors.cvv.message}</p>
            )}
          </div>
        </div>

        <Button type="submit" className="w-full" disabled={isPending}>
          {isPending ? (
            <>
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              Processando...
            </>
          ) : (
            'Pagar'
          )}
        </Button>
      </form>
    </div>
  )
}
