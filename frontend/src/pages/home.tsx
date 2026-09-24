import { Badge } from '@inmediam/ui'
import { Link } from 'react-router-dom'

import InMediamShield from '@/assets/inmediam-shield.svg'
import MediamLogo from '@/assets/mediam.svg'

export function Home() {
  return (
    <div className="mx-auto flex min-h-screen w-full flex-col items-center justify-center gap-8 bg-muted p-6">
      <div className="w-1/3 rounded-lg border border-border bg-card p-6 shadow-sm">
        <div className="mb-6 flex items-center justify-center gap-1">
          <img
            src={InMediamShield}
            className="h-8 w-8"
            alt="Logo da empresa InMediam"
          />
          <img src={MediamLogo} alt="Logo da empresa InMediam" />
        </div>

        <h1 className="text-2xl font-bold text-foreground">
          Pagamento de assinaturas
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Selecione uma cobrança para visualizar os detalhes e realizar o
          pagamento.
        </p>

        <div className="mt-6 space-y-3">
          <Link
            to="/billing/1"
            className="flex items-center justify-between rounded-md border border-border p-4 transition-colors hover:bg-muted"
          >
            <div>
              <p className="font-semibold text-foreground">Profissional</p>
              <p className="text-sm text-muted-foreground">João da Silva</p>
            </div>
            <Badge variant="warning">Pendente</Badge>
          </Link>

          <Link
            to="/billing/2"
            className="flex items-center justify-between rounded-md border border-border p-4 transition-colors hover:bg-muted"
          >
            <div>
              <p className="font-semibold text-foreground">Básico</p>
              <p className="text-sm text-muted-foreground">Maria Oliveira</p>
            </div>
            <Badge variant="success">Pago</Badge>
          </Link>
        </div>
      </div>
    </div>
  )
}
