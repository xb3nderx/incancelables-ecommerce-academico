<?php

declare(strict_types=1);

namespace Incancelables\Services;

use Incancelables\Mail\EmailSender;
use Incancelables\Models\PaymentValidationResult;
use Incancelables\Payments\PaymentProviderException;
use Incancelables\Repositories\PagoRepository;
use Incancelables\Repositories\PedidoRepository;
use Incancelables\Repositories\ProductoRepository;

final class PedidoService
{
    private const ESTADOS_QUE_AFECTAN_DISPONIBILIDAD = ['PEND_VERIF', 'PEND_PAGO', 'PROCESANDO_PAGO'];
    private const ESTADOS_ACTIVOS_CHECKOUT = ['PEND_VERIF', 'PEND_PAGO'];
    private const PRIMER_PERIODO_MINUTOS = 40;
    private const PAGO_MAX_INTENTOS = 3;
    private const RESULTADOS_SIMULACION = ['APROBADO', 'RECHAZADO'];

    public function __construct(
        private ProductoRepository $productoRepository,
        private PedidoRepository $pedidoRepository,
        private EmailVerificationService $emailVerificationService,
        private PagoRepository $pagoRepository,
        private PaymentValidationService $paymentValidationService,
        private EmailSender $emailSender,
        private string $urlVerificacionCheckout,
    ) {
    }

    public function disponibilidadDeCatalogo(): array
    {
        $this->pedidoRepository->vencerPrimerPeriodoVencidos(
            self::ESTADOS_ACTIVOS_CHECKOUT,
            self::PRIMER_PERIODO_MINUTOS
        );

        $comprometidas = $this->pedidoRepository->cantidadesComprometidasPorProducto(
            self::ESTADOS_QUE_AFECTAN_DISPONIBILIDAD
        );

        $disponibilidad = [];
        foreach ($this->productoRepository->buscarActivos() as $producto) {
            $stock = (int) $producto['stock'];
            $comprometido = $comprometidas[(int) $producto['id']] ?? 0;

            $disponibilidad[(int) $producto['id']] = max(0, $stock - $comprometido);
        }

        return $disponibilidad;
    }

    public function crearPedido(string $email, string $nombre, string $apellido, array $items): array
    {
        $agrupados = $this->agruparItems($items);

        $this->pedidoRepository->vencerPrimerPeriodoVencidos(
            self::ESTADOS_ACTIVOS_CHECKOUT,
            self::PRIMER_PERIODO_MINUTOS
        );

        $this->pedidoRepository->iniciarTransaccion();

        try {
            $resultado = $this->validarCarrito($agrupados);

            if ($resultado['resultado'] === 'correccion') {
                $this->pedidoRepository->deshacer();

                return $resultado;
            }

            $pedidoId = $this->pedidoRepository->insertarPedido([
                'email' => $email,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'estado_id' => $this->pedidoRepository->idDeEstado('PEND_VERIF'),
            ]);
            $this->pedidoRepository->insertarPedidoItems($pedidoId, $resultado['carrito']);

            $this->pedidoRepository->confirmar();

            $emailEnviado = $this->iniciarVerificacionDeEmail($pedidoId, $email);

            return [
                'resultado' => 'creado',
                'pedido' => ['id' => $pedidoId, 'estado' => 'PEND_VERIF'],
                'email_enviado' => $emailEnviado,
            ];
        } catch (\Throwable $error) {
            $this->pedidoRepository->deshacer();

            throw $error;
        }
    }

    /**
     * Validación comercial de un carrito agrupado. Debe ejecutarse dentro de una
     * transacción abierta (bloquea los productos con FOR UPDATE).
     *
     * @param array|null $excluirPedidoId Pedido cuyo compromiso de stock se excluye
     *                                    del cálculo (su propio Pedido activo).
     */
    private function validarCarrito(array $agrupados, ?int $excluirPedidoId = null): array
    {
        $productos = $this->productoRepository->bloquearPorIds(array_keys($agrupados));
        $comprometidas = $this->pedidoRepository->cantidadesComprometidasPorProducto(
            self::ESTADOS_QUE_AFECTAN_DISPONIBILIDAD,
            $excluirPedidoId
        );

        $motivos = [];
        $carrito = [];
        $disponibilidadSolicitada = [];

        foreach ($agrupados as $productoId => $item) {
            $producto = $productos[$productoId] ?? null;

            if ($producto === null) {
                $motivos[] = ['producto_id' => $productoId, 'motivo' => 'producto_inexistente'];
                continue;
            }

            if ((int) $producto['activo'] !== 1) {
                $motivos[] = ['producto_id' => $productoId, 'motivo' => 'producto_no_disponible'];
                continue;
            }

            $precioActual = (float) $producto['precio'];
            $disponibilidadActual = max(0, (int) $producto['stock'] - ($comprometidas[$productoId] ?? 0));

            $disponibilidadSolicitada[] = [
                'producto_id' => $productoId,
                'disponibilidad' => $disponibilidadActual,
            ];

            $cantidad = $item['cantidad'];

            if ($item['precio_unitario'] !== null && !$this->mismoPrecio((float) $item['precio_unitario'], $precioActual)) {
                $motivos[] = [
                    'producto_id' => $productoId,
                    'motivo' => 'precio_cambiado',
                    'precio_actual' => $precioActual,
                ];
            }

            if ($cantidad > $disponibilidadActual) {
                $motivos[] = [
                    'producto_id' => $productoId,
                    'motivo' => 'disponibilidad_insuficiente',
                    'disponibilidad_actual' => $disponibilidadActual,
                ];
                $cantidad = $disponibilidadActual;
            }

            if ($cantidad <= 0) {
                continue;
            }

            $carrito[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioActual,
            ];
        }

        if ($motivos !== []) {
            return [
                'resultado' => 'correccion',
                'carrito_corregido' => $carrito,
                'disponibilidad' => $disponibilidadSolicitada,
                'motivos' => $motivos,
            ];
        }

        return [
            'resultado' => 'valido',
            'carrito' => $carrito,
        ];
    }

    public function confirmarVerificacionEmail(string $token): array
    {
        $resultado = $this->emailVerificationService->validar($token);

        if ($resultado['resultado'] !== 'confirmado') {
            return $resultado;
        }

        $emailVerificacionId = $resultado['email_verificacion_id'];
        $pedidos = $this->pedidoRepository->buscarTodosPorEmailVerificacion($emailVerificacionId);

        $estadoPendPago = null;
        $estadoNoPagado = null;
        $habilitados = [];

        foreach ($pedidos as $pedido) {
            if ($pedido['estado'] !== 'PEND_VERIF') {
                continue;
            }

            if ($this->primerPeriodoVigente($pedido['created_at'], $pedido['ahora'])) {
                if ($estadoPendPago === null) {
                    $estadoPendPago = $this->pedidoRepository->idDeEstado('PEND_PAGO');
                }

                $this->pedidoRepository->actualizarEstado((int) $pedido['id'], $estadoPendPago);
                $habilitados[] = ['id' => (int) $pedido['id'], 'estado' => 'PEND_PAGO'];
                continue;
            }

            if ($estadoNoPagado === null) {
                $estadoNoPagado = $this->pedidoRepository->idDeEstado('NO_PAGADO');
            }

            $this->pedidoRepository->actualizarEstado((int) $pedido['id'], $estadoNoPagado);
        }

        return [
            'resultado' => 'confirmado',
            'email_verificacion_id' => $emailVerificacionId,
            'pedidos' => $habilitados,
        ];
    }

    /**
     * Consulta SOLO LECTURA: devuelve los Pedidos asociados a la verificación
     * del token (con sus items) para continuar la compra sin depender del
     * estado del frontend. No valida ni modifica la verificación.
     */
    public function obtenerPedidosPorToken(string $token): array
    {
        $registro = $this->emailVerificationService->consultarPorToken($token);

        if ($registro === null) {
            return ['resultado' => 'error', 'error' => 'token_invalido'];
        }

        if ($registro['expirado']) {
            return ['resultado' => 'error', 'error' => 'token_expirado'];
        }

        if (!$registro['verificado']) {
            return ['resultado' => 'error', 'error' => 'verificacion_pendiente'];
        }

        $pedidos = [];
        foreach ($this->pedidoRepository->buscarTodosPorEmailVerificacion($registro['id']) as $pedido) {
            $pedidos[] = [
                'id' => (int) $pedido['id'],
                'estado' => $pedido['estado'],
                'items' => $this->pedidoRepository->buscarItemsPorPedido((int) $pedido['id']),
            ];
        }

        return ['resultado' => 'ok', 'pedidos' => $pedidos];
    }

    /**
     * Reemplazo del Pedido activo de una verificación ya confirmada,
     * autorizado por el token (POST /api/email-verificaciones/pedidos/reemplazo).
     *
     * Sólo resuelve el Pedido A: toda la operación de sustitución — bloqueo del
     * Pedido, transición A → NO_PAGADO, revalidación comercial, creación del
     * Pedido B con sus PedidoItem, herencia de email_verificacion_id y la
     * conservación del estado (PEND_PAGO) — queda en el mecanismo existente
     * procesarPago(..., 'aceptar_correccion'), que la ejecuta en una única
     * transacción. No se crea ninguna otra verificación ni se envía email.
     */
    public function reemplazarPedidoPorToken(string $token, array $items): array
    {
        $registro = $this->emailVerificationService->consultarPorToken($token);

        if ($registro === null) {
            return ['resultado' => 'error', 'error' => 'token_invalido'];
        }

        if ($registro['expirado']) {
            return ['resultado' => 'error', 'error' => 'token_expirado'];
        }

        if (!$registro['verificado']) {
            return ['resultado' => 'error', 'error' => 'verificacion_pendiente'];
        }

        $activos = [];

        foreach ($this->pedidoRepository->buscarTodosPorEmailVerificacion($registro['id']) as $pedido) {
            if ($this->estaActivoCheckout($pedido['estado'])) {
                $activos[] = $pedido;
            }
        }

        if ($activos === []) {
            return ['resultado' => 'error', 'error' => 'pedido_no_activo'];
        }

        if (count($activos) > 1) {
            return ['resultado' => 'error', 'error' => 'varios_pedidos_activos'];
        }

        return $this->procesarPago((int) $activos[0]['id'], 'aceptar_correccion', $items, null, null, $token);
    }

    public function cancelarPedido(int $pedidoId, string $token): array
    {
        $this->pedidoRepository->iniciarTransaccion();

        try {
            $pedido = $this->pedidoRepository->buscarPorId($pedidoId, true);

            if ($pedido === null) {
                $this->pedidoRepository->deshacer();

                return ['resultado' => 'error', 'error' => 'pedido_inexistente'];
            }

            $autorizacion = $this->autorizarPedidoPorToken($pedido, $token);
            if ($autorizacion !== null) {
                $this->pedidoRepository->deshacer();

                return $autorizacion;
            }

            if ($this->estaActivoCheckout($pedido['estado']) && $this->primerPeriodoVencido($pedido)) {
                $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado('NO_PAGADO'));
                $this->pedidoRepository->confirmar();

                return [
                    'resultado' => 'error',
                    'error' => 'pedido_vencido',
                    'pedido' => ['id' => $pedidoId, 'estado' => 'NO_PAGADO'],
                ];
            }

            if (!$this->estaActivoCheckout($pedido['estado'])) {
                $this->pedidoRepository->deshacer();

                return [
                    'resultado' => 'error',
                    'error' => 'pedido_no_activo',
                    'pedido' => ['id' => $pedidoId, 'estado' => $pedido['estado']],
                ];
            }

            $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado('NO_PAGADO'));
            $this->pedidoRepository->confirmar();

            return [
                'resultado' => 'cancelado',
                'pedido' => ['id' => $pedidoId, 'estado' => 'NO_PAGADO'],
            ];
        } catch (\Throwable $error) {
            $this->pedidoRepository->deshacer();

            throw $error;
        }
    }

    public function procesarPago(
        int $pedidoId,
        string $accion,
        array $items = [],
        ?string $proveedor = null,
        ?string $simulacion = null,
        string $token = '',
    ): array
    {
        if (!in_array($accion, ['iniciar_pago', 'aceptar_correccion'], true)) {
            return ['resultado' => 'error', 'error' => 'accion_invalida'];
        }

        if ($this->agruparItems($items) === []) {
            return ['resultado' => 'error', 'error' => 'carrito_vacio'];
        }

        $this->pedidoRepository->iniciarTransaccion();

        try {
            $pedido = $this->pedidoRepository->buscarPorId($pedidoId, true);

            if ($pedido === null) {
                $this->pedidoRepository->deshacer();

                return ['resultado' => 'error', 'error' => 'pedido_inexistente'];
            }

            $autorizacion = $this->autorizarPedidoPorToken($pedido, $token);
            if ($autorizacion !== null) {
                $this->pedidoRepository->deshacer();

                return $autorizacion;
            }

            if ($this->estaActivoCheckout($pedido['estado']) && $this->primerPeriodoVencido($pedido)) {
                $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado('NO_PAGADO'));
                $this->pedidoRepository->confirmar();

                return [
                    'resultado' => 'error',
                    'error' => 'pedido_vencido',
                    'pedido' => ['id' => $pedidoId, 'estado' => 'NO_PAGADO'],
                ];
            }

            if (!$this->estaActivoCheckout($pedido['estado'])) {
                $this->pedidoRepository->deshacer();

                return [
                    'resultado' => 'error',
                    'error' => 'pedido_no_activo',
                    'pedido' => ['id' => $pedidoId, 'estado' => $pedido['estado']],
                ];
            }

            if ($accion === 'iniciar_pago' && $pedido['estado'] !== 'PEND_PAGO') {
                $this->pedidoRepository->deshacer();

                return [
                    'resultado' => 'error',
                    'error' => 'pedido_no_pendiente_de_pago',
                    'pedido' => ['id' => $pedidoId, 'estado' => $pedido['estado']],
                ];
            }

            $this->pedidoRepository->vencerPrimerPeriodoVencidos(
                self::ESTADOS_ACTIVOS_CHECKOUT,
                self::PRIMER_PERIODO_MINUTOS
            );

            if ($accion === 'iniciar_pago') {
                return $this->iniciarPago($pedido, $items, $proveedor, $simulacion);
            }

            return $this->aceptarCorreccion($pedido, $items);
        } catch (\Throwable $error) {
            $this->pedidoRepository->deshacer();

            throw $error;
        }
    }

    /**
     * Tercera validación + inicio real del intento de pago con DummyPaymentProvider.
     *
     * Fase A (transacción de procesarPago): valida el carrito recibido, crea el
     * Pago y su PaymentValidationResult (EN_PROCESO) y pasa el Pedido a
     * PROCESANDO_PAGO — sólo cuando realmente se inicia el intento — antes del
     * COMMIT. Fase B: en una segunda transacción consulta/normaliza el resultado
     * del proveedor y aplica las reglas de negocio (PAGADO / PEND_PAGO /
     * NO_PAGADO / PAGADO_STOCK_NO_AFECTADO).
     */
    private function iniciarPago(array $pedido, array $items, ?string $proveedor, ?string $simulacion): array
    {
        $pedidoId = (int) $pedido['id'];

        if ($simulacion !== null && !in_array($simulacion, self::RESULTADOS_SIMULACION, true)) {
            $this->pedidoRepository->deshacer();

            return ['resultado' => 'error', 'error' => 'simulacion_invalida'];
        }

        if ($proveedor === null || $proveedor === '') {
            $this->pedidoRepository->deshacer();

            return ['resultado' => 'error', 'error' => 'proveedor_faltante'];
        }

        $proveedorDatos = $this->pagoRepository->buscarProveedor($proveedor);

        if ($proveedorDatos === null) {
            $this->pedidoRepository->deshacer();

            return ['resultado' => 'error', 'error' => 'proveedor_inexistente'];
        }

        if ($proveedorDatos['codigo'] !== 'DUMMY') {
            $this->pedidoRepository->deshacer();

            return ['resultado' => 'error', 'error' => 'proveedor_no_soportado'];
        }

        if ($this->pagoRepository->pedidoTieneValidacionAprobada($pedidoId)) {
            $this->pedidoRepository->deshacer();

            return [
                'resultado' => 'error',
                'error' => 'pago_ya_aprobado',
                'pedido' => ['id' => $pedidoId, 'estado' => $pedido['estado']],
            ];
        }

        $persistidos = $this->agruparItems($this->pedidoRepository->buscarItemsPorPedido($pedidoId));
        $recibidos = $this->agruparItems($items);
        $diferencias = $this->diferenciasDeCarrito($recibidos, $persistidos);

        if ($diferencias !== []) {
            return $this->correccionPorCarritoDesactualizado($pedido, $persistidos, $diferencias);
        }

        $resultado = $this->validarCarrito($recibidos, $pedidoId);

        if ($resultado['resultado'] === 'correccion') {
            $respuesta = [
                'resultado' => 'correccion',
                'pedido' => ['id' => $pedidoId, 'estado' => $pedido['estado']],
                'carrito_corregido' => $resultado['carrito_corregido'],
                'disponibilidad' => $resultado['disponibilidad'],
                'motivos' => $resultado['motivos'],
                'catalogo' => $this->catalogoActivo(),
            ];

            $this->pedidoRepository->deshacer();

            return $respuesta;
        }

        if ($this->pagoRepository->contarIntentos($pedidoId) >= self::PAGO_MAX_INTENTOS) {
            $this->pedidoRepository->deshacer();

            return [
                'resultado' => 'error',
                'error' => 'maximo_intentos_alcanzado',
                'pedido' => ['id' => $pedidoId, 'estado' => $pedido['estado']],
                'intentos' => $this->pagoRepository->contarIntentos($pedidoId),
                'maximo_intentos' => self::PAGO_MAX_INTENTOS,
            ];
        }

        $importe = $this->totalDelPedido($persistidos);

        $pagoId = $this->paymentValidationService->registrarIntento(
            $pedidoId,
            $importe,
            (int) $proveedorDatos['id']
        );

        $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado('PROCESANDO_PAGO'));
        $this->pedidoRepository->confirmar();

        return $this->procesarResultadoPago($pedidoId, $pagoId, $importe, $simulacion);
    }

    /**
     * Fase B: nueva transacción con el Pedido bloqueado. Interactúa con el
     * proveedor vía PaymentValidationService y aplica el resultado normalizado.
     */
    private function procesarResultadoPago(int $pedidoId, int $pagoId, float $importe, ?string $simulacion): array
    {
        try {
            $this->pedidoRepository->iniciarTransaccion();

            $pedido = $this->pedidoRepository->buscarPorId($pedidoId, true);

            if ($pedido === null || $pedido['estado'] !== 'PROCESANDO_PAGO') {
                $this->pedidoRepository->deshacer();

                return ['resultado' => 'error', 'error' => 'pedido_no_procesando'];
            }

            try {
                $resultado = $this->paymentValidationService->procesarIntento($pagoId, $importe, $simulacion);
            } catch (PaymentProviderException $error) {
                $this->pedidoRepository->deshacer();

                error_log('[incancelables] proveedor de pago fallo para el pago ' . $pagoId . ': ' . $error->getMessage());

                return [
                    'resultado' => 'en_proceso',
                    'pedido' => ['id' => $pedidoId, 'estado' => 'PROCESANDO_PAGO'],
                    'pago' => ['id' => $pagoId, 'importe' => $importe],
                ];
            }

            $respuesta = $this->aplicarResultadoPago($pedido, $pagoId, $importe, $resultado);

            $this->pedidoRepository->confirmar();

            return $respuesta;
        } catch (\Throwable $error) {
            $this->pedidoRepository->deshacer();

            throw $error;
        }
    }

    private function aplicarResultadoPago(array $pedido, int $pagoId, float $importe, PaymentValidationResult $resultado): array
    {
        if ($resultado->estado === 'APROBADO') {
            return $this->aplicarAprobado($pedido, $pagoId, $importe, $resultado);
        }

        if ($resultado->estado === 'RECHAZADO') {
            return $this->aplicarRechazado($pedido, $pagoId, $importe, $resultado);
        }

        return [
            'resultado' => 'en_proceso',
            'pedido' => ['id' => (int) $pedido['id'], 'estado' => 'PROCESANDO_PAGO'],
            'pago' => ['id' => $pagoId, 'importe' => $importe],
            'validacion' => [
                'estado' => $resultado->estado,
                'referencia_proveedor' => $resultado->referenciaProveedor,
            ],
        ];
    }

    /**
     * APROBADO: operación atómica e idempotente. Si el importe informado no
     * coincide o el stock no puede afectarse, se registra el resultado y el
     * caso de revisión PAGADO_STOCK_NO_AFECTADO SIN pasar a PAGADO (y sin
     * descontar stock). Nunca puede haber dos APROBADO con efecto comercial.
     */
    private function aplicarAprobado(array $pedido, int $pagoId, float $importe, PaymentValidationResult $resultado): array
    {
        $pedidoId = (int) $pedido['id'];
        $items = $this->pedidoRepository->buscarItemsPorPedido($pedidoId);

        $respuestaBase = [
            'resultado' => 'aprobado',
            'pago' => ['id' => $pagoId, 'importe' => $importe],
            'validacion' => ['estado' => 'APROBADO', 'referencia_proveedor' => $resultado->referenciaProveedor],
        ];

        if (!$this->mismoPrecio($resultado->importe, $importe)) {
            $this->pagoRepository->insertarCasoRevision($pagoId, 'PAGADO_STOCK_NO_AFECTADO');

            return $respuestaBase + [
                'pedido' => ['id' => $pedidoId, 'estado' => 'PROCESANDO_PAGO'],
                'caso_revision' => ['tipo' => 'PAGADO_STOCK_NO_AFECTADO', 'estado' => 'PENDIENTE'],
            ];
        }

        $cantidades = [];
        foreach ($items as $item) {
            $productoId = $item['producto_id'];
            $cantidades[$productoId] = ($cantidades[$productoId] ?? 0) + $item['cantidad'];
        }

        $productos = $this->productoRepository->bloquearPorIds(array_keys($cantidades));

        foreach ($cantidades as $productoId => $cantidad) {
            $producto = $productos[$productoId] ?? null;

            if ($producto === null || (int) $producto['stock'] < $cantidad) {
                $this->pagoRepository->insertarCasoRevision($pagoId, 'PAGADO_STOCK_NO_AFECTADO');
                $this->pedidoRepository->actualizarEstado(
                    $pedidoId,
                    $this->pedidoRepository->idDeEstado('PAGADO_STOCK_NO_AFECTADO')
                );

                return $respuestaBase + [
                    'pedido' => ['id' => $pedidoId, 'estado' => 'PAGADO_STOCK_NO_AFECTADO'],
                    'caso_revision' => ['tipo' => 'PAGADO_STOCK_NO_AFECTADO', 'estado' => 'PENDIENTE'],
                ];
            }
        }

        foreach ($cantidades as $productoId => $cantidad) {
            $this->productoRepository->descontarStock($productoId, $cantidad);
        }

        $this->pagoRepository->resolverCasosRevision($pagoId, 'PAGO_PROCESANDO_EXCEDIDO');
        $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado('PAGADO'));

        return $respuestaBase + ['pedido' => ['id' => $pedidoId, 'estado' => 'PAGADO']];
    }

    /**
     * RECHAZADO definitivo: no crea otro intento automáticamente. Si se alcanzó
     * PAGO_MAX_INTENTOS (o ya no puede volver a PEND_PAGO) → NO_PAGADO; si
     * quedan intentos y el primer período sigue vigente → PEND_PAGO.
     */
    private function aplicarRechazado(array $pedido, int $pagoId, float $importe, PaymentValidationResult $resultado): array
    {
        $pedidoId = (int) $pedido['id'];

        $this->pagoRepository->resolverCasosRevision($pagoId, 'PAGO_PROCESANDO_EXCEDIDO');

        $intentos = $this->pagoRepository->contarIntentos($pedidoId);

        if ($intentos >= self::PAGO_MAX_INTENTOS || !$this->primerPeriodoVigente($pedido['created_at'], $pedido['ahora'])) {
            $nuevoEstado = 'NO_PAGADO';
        } else {
            $nuevoEstado = 'PEND_PAGO';
        }

        $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado($nuevoEstado));

        return [
            'resultado' => 'rechazado',
            'pedido' => ['id' => $pedidoId, 'estado' => $nuevoEstado],
            'intentos' => $intentos,
            'maximo_intentos' => self::PAGO_MAX_INTENTOS,
            'pago' => ['id' => $pagoId, 'importe' => $importe],
            'validacion' => ['estado' => 'RECHAZADO', 'referencia_proveedor' => $resultado->referenciaProveedor],
        ];
    }

    private function correccionPorCarritoDesactualizado(array $pedido, array $persistidos, array $diferencias): array
    {
        $validacionPersistidos = $this->validarCarrito($persistidos, (int) $pedido['id']);

        $motivos = [];
        foreach ($diferencias as $productoId) {
            $motivos[] = ['producto_id' => $productoId, 'motivo' => 'carrito_desactualizado'];
        }

        foreach ($validacionPersistidos['motivos'] ?? [] as $motivo) {
            $motivos[] = $motivo;
        }

        $carritoCorregido = $validacionPersistidos['carrito']
            ?? $validacionPersistidos['carrito_corregido']
            ?? [];

        $disponibilidad = $validacionPersistidos['disponibilidad'] ?? [];

        if ($disponibilidad === []) {
            foreach ($this->disponibilidadDeCatalogo() as $productoId => $disponible) {
                $disponibilidad[] = ['producto_id' => $productoId, 'disponibilidad' => $disponible];
            }
        }

        $respuesta = [
            'resultado' => 'correccion',
            'pedido' => ['id' => (int) $pedido['id'], 'estado' => $pedido['estado']],
            'carrito_corregido' => $carritoCorregido,
            'disponibilidad' => $disponibilidad,
            'motivos' => $motivos,
            'catalogo' => $this->catalogoActivo(),
        ];

        $this->pedidoRepository->deshacer();

        return $respuesta;
    }

    private function diferenciasDeCarrito(array $recibidos, array $persistidos): array
    {
        $productoIds = array_unique(array_merge(array_keys($recibidos), array_keys($persistidos)));
        $diferencias = [];

        foreach ($productoIds as $productoId) {
            $cantidadRecibida = $recibidos[$productoId]['cantidad'] ?? null;
            $cantidadPersistida = $persistidos[$productoId]['cantidad'] ?? null;

            if ($cantidadRecibida !== $cantidadPersistida) {
                $diferencias[] = (int) $productoId;
            }
        }

        return $diferencias;
    }

    private function totalDelPedido(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += $item['cantidad'] * $item['precio_unitario'];
        }

        return round($total, 2);
    }

    private function aceptarCorreccion(array $pedido, array $items): array
    {
        $estadoHeredado = $pedido['estado'];

        $this->pedidoRepository->actualizarEstado(
            (int) $pedido['id'],
            $this->pedidoRepository->idDeEstado('NO_PAGADO')
        );

        $resultado = $this->validarCarrito($this->agruparItems($items));

        if ($resultado['resultado'] === 'correccion') {
            $respuesta = [
                'resultado' => 'correccion',
                'pedido' => ['id' => (int) $pedido['id'], 'estado' => $estadoHeredado],
                'carrito_corregido' => $resultado['carrito_corregido'],
                'disponibilidad' => $resultado['disponibilidad'],
                'motivos' => $resultado['motivos'],
                'catalogo' => $this->catalogoActivo(),
            ];

            $this->pedidoRepository->deshacer();

            return $respuesta;
        }

        $nuevoPedidoId = $this->pedidoRepository->insertarPedido([
            'email' => $pedido['email'],
            'nombre' => $pedido['nombre'] ?? '',
            'apellido' => $pedido['apellido'] ?? '',
            'estado_id' => $this->pedidoRepository->idDeEstado($estadoHeredado),
            'email_verificacion_id' => $pedido['email_verificacion_id'],
        ]);
        $this->pedidoRepository->insertarPedidoItems($nuevoPedidoId, $resultado['carrito']);

        $this->pedidoRepository->confirmar();

        if ($estadoHeredado === 'PEND_VERIF' && $pedido['email_verificacion_id'] === null) {
            $this->iniciarVerificacionDeEmail($nuevoPedidoId, $pedido['email']);
        }

        return [
            'resultado' => 'reemplazado',
            'pedido_anterior' => ['id' => (int) $pedido['id'], 'estado' => 'NO_PAGADO'],
            'pedido' => ['id' => $nuevoPedidoId, 'estado' => $estadoHeredado],
        ];
    }

    private function catalogoActivo(): array
    {
        $disponibilidad = $this->disponibilidadDeCatalogo();

        $catalogo = [];
        foreach ($this->productoRepository->buscarActivos() as $producto) {
            $id = (int) $producto['id'];

            $catalogo[] = [
                'id' => $id,
                'nombre' => $producto['nombre'],
                'descripcion' => $producto['descripcion'],
                'precio' => (float) $producto['precio'],
                'disponibilidad' => $disponibilidad[$id] ?? 0,
            ];
        }

        return $catalogo;
    }

    private function estaActivoCheckout(string $estado): bool
    {
        return in_array($estado, self::ESTADOS_ACTIVOS_CHECKOUT, true);
    }

    private function autorizarPedidoPorToken(array $pedido, string $token): ?array
    {
        $registro = $this->emailVerificationService->consultarPorToken($token);

        if ($registro === null) {
            return ['resultado' => 'error', 'error' => 'token_invalido'];
        }

        if ($registro['expirado']) {
            return ['resultado' => 'error', 'error' => 'token_expirado'];
        }

        if (!$registro['verificado']) {
            return ['resultado' => 'error', 'error' => 'verificacion_pendiente'];
        }

        if ($pedido['email_verificacion_id'] !== $registro['id']) {
            return ['resultado' => 'error', 'error' => 'pedido_no_autorizado'];
        }

        return null;
    }

    private function primerPeriodoVencido(array $pedido): bool
    {
        return !$this->primerPeriodoVigente($pedido['created_at'], $pedido['ahora']);
    }

    private function primerPeriodoVigente(string $createdAT, string $ahoraMariaDB): bool
    {
        return (strtotime($ahoraMariaDB) - strtotime($createdAT)) < self::PRIMER_PERIODO_MINUTOS * 60;
    }

    public function reenviarVerificacionEmail(int $pedidoId): array
    {
        $pedido = $this->pedidoRepository->buscarPorId($pedidoId);

        if ($pedido === null) {
            return ['resultado' => 'error', 'error' => 'pedido_inexistente'];
        }

        if ($this->estaActivoCheckout($pedido['estado']) && $this->primerPeriodoVencido($pedido)) {
            $this->pedidoRepository->actualizarEstado($pedidoId, $this->pedidoRepository->idDeEstado('NO_PAGADO'));

            return [
                'resultado' => 'error',
                'error' => 'pedido_vencido',
                'pedido' => ['id' => $pedidoId, 'estado' => 'NO_PAGADO'],
            ];
        }

        if ($pedido['estado'] !== 'PEND_VERIF') {
            return ['resultado' => 'error', 'error' => 'pedido_no_pendiente_de_verificacion'];
        }

        try {
            if ($pedido['email_verificacion_id'] === null) {
                return $this->iniciarVerificacionParaReenvio($pedido);
            }

            $resultado = $this->emailVerificationService->reenviar($pedido['email_verificacion_id']);

            if ($resultado['resultado'] === 'no_encontrada') {
                return $this->iniciarVerificacionParaReenvio($pedido);
            }

            if ($resultado['resultado'] !== 'token_generado') {
                return $resultado;
            }

            if (!$this->enviarEmailVerificacion($pedido['email'], $resultado['token'])) {
                return ['resultado' => 'error', 'error' => 'error_envio_email'];
            }

            $this->emailVerificationService->registrarEnvio($pedido['email_verificacion_id']);

            return [
                'resultado' => 'reenviado',
                'email_verificacion_id' => $pedido['email_verificacion_id'],
            ];
        } catch (\Throwable $error) {
            error_log('[incancelables] reenvio de verificacion fallo para el pedido ' . $pedidoId . ': ' . $error->getMessage());

            return ['resultado' => 'error', 'error' => 'error_envio_email'];
        }
    }

    private function iniciarVerificacionDeEmail(int $pedidoId, string $email): bool
    {
        try {
            $verificacion = $this->emailVerificationService->iniciar($email);
            $this->pedidoRepository->asociarEmailVerificacion($pedidoId, $verificacion['email_verificacion_id']);

            if (!$this->enviarEmailVerificacion($email, $verificacion['token'])) {
                return false;
            }

            $this->emailVerificationService->registrarEnvio($verificacion['email_verificacion_id']);

            return true;
        } catch (\Throwable $error) {
            error_log('[incancelables] verificacion de email no iniciada para el pedido ' . $pedidoId . ': ' . $error->getMessage());

            return false;
        }
    }

    private function iniciarVerificacionParaReenvio(array $pedido): array
    {
        $verificacion = $this->emailVerificationService->iniciar($pedido['email']);
        $this->pedidoRepository->asociarEmailVerificacion($pedido['id'], $verificacion['email_verificacion_id']);

        if (!$this->enviarEmailVerificacion($pedido['email'], $verificacion['token'])) {
            return ['resultado' => 'error', 'error' => 'error_envio_email'];
        }

        $this->emailVerificationService->registrarEnvio($verificacion['email_verificacion_id']);

        return [
            'resultado' => 'reenviado',
            'email_verificacion_id' => $verificacion['email_verificacion_id'],
        ];
    }

    private function enviarEmailVerificacion(string $email, string $token): bool
    {
        $enlace = $this->urlVerificacionCheckout . '?flow=checkout&token=' . urlencode($token);

        $asunto = 'Confirmá tu compra en Incancelables';

        $cuerpo = "Hola,\n\n"
            . "Para confirmar tu email y continuar con tu compra, ingresá al siguiente enlace:\n\n"
            . $enlace . "\n\n"
            . "Si no realizaste esta solicitud, podés ignorar este mensaje.\n\n"
            . "Incancelables";

        return $this->emailSender->enviar($email, $asunto, $cuerpo);
    }

    private function agruparItems(array $items): array
    {
        $agrupados = [];

        foreach ($items as $item) {
            $productoId = $item['producto_id'];

            if (!isset($agrupados[$productoId])) {
                $agrupados[$productoId] = [
                    'producto_id' => $productoId,
                    'cantidad' => 0,
                    'precio_unitario' => $item['precio_unitario'],
                ];
            }

            $agrupados[$productoId]['cantidad'] += $item['cantidad'];
        }

        return $agrupados;
    }

    private function mismoPrecio(float $uno, float $otro): bool
    {
        return number_format($uno, 2, '.', '') === number_format($otro, 2, '.', '');
    }
}
