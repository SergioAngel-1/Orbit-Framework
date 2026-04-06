import React, { useEffect, useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../../contexts/AuthContext';
import { orderService } from '../../../services/api';
import productApiService from '../../../services/products/productApiService';
import logger from '../../../utils/logger';
import Loader from '../../ui/Loader';
import alertService from '../../../services/alertService';
import CheckoutSuccess from '../../checkout/CheckoutSuccess';
import VirtualCoinPrice from '../../common/VirtualCoinPrice';
import CollapsibleSection from '../../common/CollapsibleSection';
import Pagination from '../../common/Pagination';
import { FiShoppingBag, FiRepeat, FiEye, FiAward, FiDollarSign, FiStar, FiPackage } from 'react-icons/fi';
import useOrderRating from '../../../hooks/useOrderRating';
import OrderRatingModal from '../../reviews/OrderRatingModal';
import type { PendingOrder } from '../../../services/reviews/reviewTypes';
import { useLanguage } from '../../../contexts/LanguageContext';
import { fluidSizing } from '../../../utils/fluidSizing';

const ORDERS_PER_PAGE = 5;

// Tipos para los pedidos
interface OrderItem {
  id: number;
  name: string;
  price: number;
  quantity: number;
  image: string;
  product_id: number;
}

// Tipos de orden especiales
type OrderType = 'regular' | 'membership_purchase' | 'virtual_coins_purchase';

interface Order {
  id: number;
  date: string;
  status: 'pending' | 'processing' | 'completed' | 'cancelled' | 'on-hold' | 'refunded' | 'failed';
  total: number;
  deliveryDate?: string | null;
  paymentMethod?: string;
  shippingMethod?: string;
  shippingAddress?: string;
  items: OrderItem[];
  orderType: OrderType;
  // Metadatos específicos para membresías
  membershipLevel?: number;
  membershipDays?: number;
  membershipPoints?: number;
  // Metadatos específicos para paquetes FC
  fcTotalCoins?: number;
  // Metadatos de calificación de pedido
  orderRating?: number;
  orderObservation?: string;
}

interface OrdersSectionProps {
  onClose?: () => void;
}

const DELIVERY_META_KEYS = ['delivery_date', 'deliveryDate', '_delivery_date', 'delivery_datetime', 'delivery_datetime_local'];

const normalizeShippingMethod = (shippingLine?: any): 'Express' | 'Normal' | undefined => {
  if (!shippingLine) return undefined;

  const raw = `${shippingLine.method_id ?? ''} ${shippingLine.method_title ?? ''}`.toLowerCase();
  if (raw.includes('express') || raw.includes('premium')) {
    return 'Express';
  }

  return 'Normal';
};

const extractDeliveryDate = (order: any): string | null => {
  if (!order) return null;

  const metaMatch = order.meta_data?.find((meta: any) => DELIVERY_META_KEYS.includes(meta?.key));
  const metaValue = metaMatch?.value;

  if (typeof metaValue === 'string' && metaValue.trim()) {
    return metaValue;
  }

  if (metaValue && typeof metaValue === 'object') {
    const nestedValue = metaValue.date || metaValue.datetime || metaValue.value;
    if (typeof nestedValue === 'string' && nestedValue.trim()) {
      return nestedValue;
    }
  }

  if (order.date_completed) {
    return order.date_completed;
  }

  return null;
};

const buildShippingAddress = (shipping?: any): string | undefined => {
  if (!shipping) return undefined;

  const fullName = [shipping.first_name, shipping.last_name].filter(Boolean).join(' ').trim();
  const cityState = [shipping.city, shipping.state].filter(Boolean).join(', ').trim();

  const lines = [
    fullName || null,
    shipping.company || null,
    shipping.address_1 || null,
    shipping.address_2 || null,
    cityState || null,
    shipping.postcode || null,
    shipping.country || null
  ].filter((line): line is string => Boolean(line && line.trim()));

  if (!lines.length) {
    return undefined;
  }

  return lines.join('\n');
};

const OrdersSection: React.FC<OrdersSectionProps> = ({ onClose }) => {
  const { user } = useAuth();
  const { t } = useTranslation('ordersSection');
  const { currentLang, localizedPath } = useLanguage();
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [helpOrderId, setHelpOrderId] = useState<number | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [orderForRating, setOrderForRating] = useState<PendingOrder | null>(null);
  const [reorderingOrderId, setReorderingOrderId] = useState<number | null>(null);
  const { rateOrder, confirmOrder, submitting: ratingSubmitting, confirming: confirmingOrder, reviewPoints } = useOrderRating();

  // Calcular órdenes paginadas
  const totalPages = useMemo(() => Math.ceil(orders.length / ORDERS_PER_PAGE), [orders.length]);
  const paginatedOrders = useMemo(() => {
    const startIndex = (currentPage - 1) * ORDERS_PER_PAGE;
    return orders.slice(startIndex, startIndex + ORDERS_PER_PAGE);
  }, [orders, currentPage]);

  // Reset página cuando cambian las órdenes
  useEffect(() => {
    setCurrentPage(1);
  }, [orders.length]);

  // Cargar los retiros del usuario
  useEffect(() => {
    const fetchOrders = async () => {
      if (!user?.id) return;
      
      try {
        setLoading(true);
        setError(null);
        
        logger.info('OrdersSection', 'Obteniendo retiros para el usuario:', user.id);
        const response = await orderService.getCustomerOrders(user.id);
        logger.info('OrdersSection', 'Respuesta de retiros:', response.data);
        
        // Si la respuesta es null, undefined, o no es un array, significa que no hay retiros
        if (!response.data || !Array.isArray(response.data)) {
          // No hay retiros, pero esto no es un error
          setOrders([]);
          return;
        }
        
        // Transformar los datos de la API al formato que necesitamos
        const formattedOrders: Order[] = response.data.map((order: any) => {
          const primaryShippingLine = order.shipping_lines?.[0];
          
          // Extraer tipo de orden desde meta_data
          const orderTypeMeta = order.meta_data?.find((m: any) => m.key === '_order_type');
          const orderType: OrderType = orderTypeMeta?.value || 'regular';
          
          // Extraer metadatos de membresía
          const membershipLevel = order.meta_data?.find((m: any) => m.key === '_starter_membership_level')?.value;
          const membershipDays = order.meta_data?.find((m: any) => m.key === '_starter_membership_duration_days')?.value;
          const membershipPoints = order.meta_data?.find((m: any) => m.key === '_starter_membership_monthly_points')?.value;
          
          // Extraer metadatos de paquete FC
          const fcTotalCoins = order.meta_data?.find((m: any) => m.key === '_starter_fc_total_coins')?.value;
          
          // Extraer metadatos de calificación de pedido
          const orderRating = order.meta_data?.find((m: any) => m.key === '_starter_order_rating')?.value;
          const orderObservation = order.meta_data?.find((m: any) => m.key === '_starter_order_observation')?.value;

          return {
            id: order.id,
            date: order.date_created,
            status: order.status,
            total: parseFloat(order.total),
            deliveryDate: extractDeliveryDate(order),
            paymentMethod: order.payment_method_title || order.payment_method,
            shippingMethod: normalizeShippingMethod(primaryShippingLine),
            shippingAddress: buildShippingAddress(order.shipping),
            items: order.line_items.map((item: any) => ({
              id: item.id,
              name: item.name,
              price: parseFloat(item.price),
              quantity: item.quantity,
              image: item.image?.src || '/wp-content/themes/Starter/assets/img/no-image.svg',
              product_id: item.product_id
            })),
            orderType,
            membershipLevel: membershipLevel ? parseInt(membershipLevel) : undefined,
            membershipDays: membershipDays ? parseInt(membershipDays) : undefined,
            membershipPoints: membershipPoints ? parseInt(membershipPoints) : undefined,
            fcTotalCoins: fcTotalCoins ? parseInt(fcTotalCoins) : undefined,
            orderRating: orderRating ? parseInt(orderRating) : undefined,
            orderObservation: orderObservation || undefined,
          };
        });
        
        setOrders(formattedOrders);
      } catch (err: any) {
        logger.error('OrdersSection', 'Error al obtener retiros:', err);
        
        // Caso 1: Error 404 - Usuario no tiene retiros
        if (err.response && err.response.status === 404) {
          // No hay retiros, esto no es un error
          setOrders([]);
          return;
        }
        
        // Caso 2: Error de permisos - "Sorry, you cannot list resources"
        if (err.response && 
            err.response.data && 
            (typeof err.response.data === 'string' && err.response.data.includes('cannot list resources') ||
             err.response.data.message && err.response.data.message.includes('cannot list resources'))) {
          // Usuario nuevo o sin permisos para ver retiros
          setOrders([]);
          return;
        }
        
        // Caso 3: Usuario nuevo sin pedidos pero la API devuelve otro tipo de respuesta
        if (err.response && 
            (err.response.status === 401 || err.response.status === 403)) {
          // Problemas de autenticación - asumimos que es un usuario nuevo
          setOrders([]);
          return;
        }
        
        // Caso 4: Es un error real de la API
        setError(t('error'));
      } finally {
        setLoading(false);
      }
    };
    
    fetchOrders();
  }, [user?.id]);

  const getStatusLabel = (status: Order['status']) => {
    const colorMap: Record<string, string> = {
      pending: 'bg-yellow-100 text-yellow-800',
      processing: 'bg-blue-100 text-blue-800',
      completed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
      'on-hold': 'bg-orange-100 text-orange-800',
      refunded: 'bg-purple-100 text-purple-800',
      failed: 'bg-red-100 text-red-800',
    };
    return {
      text: t(`status.${status}`, { defaultValue: status }),
      color: colorMap[status] || 'bg-gray-100 text-gray-800',
    };
  };

  const formatDate = (dateString: string) => {
    if (!dateString) return t('details.notAvailable');
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return t('details.notAvailable');
    const locale = currentLang === 'en' ? 'en-US' : 'es-CO';
    return date.toLocaleDateString(locale, {
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  };

  const formatDateTime = (dateString?: string | null) => {
    if (!dateString) return null;
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return null;
    const locale = currentLang === 'en' ? 'en-US' : 'es-CO';
    return date.toLocaleString(locale, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  // Función eliminada - ahora usamos VirtualCoinPrice

  const handleReorder = async (orderId: number) => {
    if (reorderingOrderId) return; // Prevenir doble click
    setReorderingOrderId(orderId);
    logger.info('OrdersSection', 'Iniciando reorden para retiro ID:', orderId);
    
    const orderToReorder = orders.find(order => order.id === orderId);
    if (!orderToReorder) {
      logger.error('OrdersSection', 'No se encontró el retiro con ID:', orderId);
      alertService.error(t('reorder.notFound'));
      return;
    }
    
    if (!orderToReorder.items || orderToReorder.items.length === 0) {
      logger.error('OrdersSection', 'El retiro no tiene items:', orderToReorder);
      alertService.error(t('reorder.noItems'));
      return;
    }

    try {
      // Mostrar mensaje de carga
      alertService.info(t('reorder.validating'));
      
      // Construir nueva selección validando disponibilidad de cada beneficio
      const newCartItems: any[] = [];
      const unavailableProducts: string[] = [];
      
      for (const item of orderToReorder.items) {
        if (!item.product_id || item.product_id <= 0) {
          logger.warn('OrdersSection', `Beneficio inválido en el retiro ${orderId}`);
          unavailableProducts.push(item.name);
          continue;
        }
        
        try {
          // Consultar el beneficio desde la API para obtener su estado actual
          logger.info('OrdersSection', `Validando disponibilidad del beneficio ${item.product_id}: ${item.name}`);
          const productResponse = await productApiService.getById(item.product_id);
          const product = productResponse.data;
          
          // Validar que el beneficio esté disponible
          if (!product || product.stock_status !== 'instock') {
            logger.warn('OrdersSection', `Beneficio ${item.product_id} (${item.name}) no está disponible. Stock status: ${product?.stock_status || 'N/A'}`);
            unavailableProducts.push(item.name);
            continue;
          }
          
          // Validar que el beneficio esté publicado
          if (product.status !== 'publish') {
            logger.warn('OrdersSection', `Beneficio ${item.product_id} (${item.name}) no está publicado. Status: ${product.status}`);
            unavailableProducts.push(item.name);
            continue;
          }
          
          // Beneficio válido y disponible - agregarlo a la selección
          newCartItems.push({
            id: item.product_id,
            product: product,
            quantity: Math.max(1, Number(item.quantity || 1)),
            variation_id: undefined,
            variation: undefined
          });
          
          logger.info('OrdersSection', `Beneficio ${item.product_id} (${item.name}) agregado a la selección - Stock: ${product.stock_status}`);
          
        } catch (error: any) {
          // Si hay error al consultar el beneficio (404, etc.), marcarlo como no disponible
          logger.error('OrdersSection', `Error al validar beneficio ${item.product_id}:`, error);
          unavailableProducts.push(item.name);
        }
      }
      
      // Verificar si hay beneficios disponibles para agregar
      if (newCartItems.length === 0) {
        alertService.error(t('reorder.noneAvailable'));
        return;
      }
      
      // Cerrar el modal
      if (onClose) {
        onClose();
      }
      
      // Marcar que estamos haciendo un reorder (para evitar que la selección híbrida lo sobrescriba)
      localStorage.setItem('cart_reorder_in_progress', 'true');
      
      // Reemplazar completamente la selección en localStorage
      localStorage.setItem('cart_items', JSON.stringify(newCartItems));
      localStorage.removeItem('cart_coupon');
      
      logger.info('OrdersSection', `Selección reemplazada con ${newCartItems.length} beneficios del retiro #${orderId}`);
      
      // Mostrar mensaje apropiado según los resultados
      if (unavailableProducts.length > 0) {
        alertService.warning(
          t('reorder.partialSuccess', { available: newCartItems.length, unavailable: unavailableProducts.length })
        );
      } else {
        alertService.success(t('reorder.success', { count: newCartItems.length }));
      }
      
      // Navegar a finalizar retiro (con refresh para que la selección híbrida procese el reorder)
      setTimeout(() => {
        window.location.href = localizedPath('/finalizar-retiro');
      }, 500);
      
    } catch (err) {
      logger.error('OrdersSection', 'Error al reordenar beneficios:', err);
      alertService.error(t('reorder.error'));
    } finally {
      setReorderingOrderId(null);
    }
  };

  const handleRequestHelp = (orderId: number) => {
    setHelpOrderId(orderId);
  };

  const handleBackToOrders = () => {
    setHelpOrderId(null);
  };

  const orderToPendingOrder = (order: Order, status: 'processing' | 'completed' = 'completed'): PendingOrder => ({
    order_id: order.id,
    date: order.date,
    total: String(order.total),
    status,
    items: order.items.map(item => ({
      product_id: item.product_id,
      name: item.name,
      image: item.image,
      quantity: item.quantity,
      already_reviewed: false,
    })),
    all_products_reviewed: false,
  });

  const handleRateOrder = (order: Order) => {
    setOrderForRating(orderToPendingOrder(order));
  };

  const handleConfirmOrder = (order: Order) => {
    alertService.confirm(
      t('confirmReceived.message', { orderId: order.id, defaultValue: `¿Confirmas que recibiste el pedido #${order.id}?` }),
      async () => {
        try {
          const pendingOrder = orderToPendingOrder(order, 'processing');
          const confirmedOrder = await confirmOrder(pendingOrder);
          // Actualizar estado local del pedido a completed
          setOrders(prev => prev.map(o => 
            o.id === order.id ? { ...o, status: 'completed' as const } : o
          ));
          // Abrir rating modal con el pedido confirmado
          setOrderForRating(confirmedOrder);
        } catch {
          // Error ya logueado en el hook
        }
      }
    );
  };

  const handleRatingSuccess = (orderId: number, rating: number) => {
    setOrders(prev => prev.map(o => 
      o.id === orderId ? { ...o, orderRating: rating } : o
    ));
    setOrderForRating(null);
  };

  if (helpOrderId) {
    const selectedOrder = orders.find(order => order.id === helpOrderId);
    const historyDetails = selectedOrder
      ? {
          placedAt: formatDateTime(selectedOrder.date) ?? t('details.notAvailable'),
          deliveryDate: selectedOrder.deliveryDate
            ? formatDateTime(selectedOrder.deliveryDate) ?? t('details.pendingSchedule')
            : t('details.pendingSchedule'),
          deliveryStatus: getStatusLabel(selectedOrder.status).text,
          shippingMethod: selectedOrder.shippingMethod ?? t('details.toCoordinate'),
          shippingAddress: selectedOrder.shippingAddress,
          paymentMethod: selectedOrder.paymentMethod,
          totalFormatted: `${selectedOrder.total} FC`
        }
      : undefined;

    return (
      <div className="space-y-4">
        <CheckoutSuccess
          orderId={helpOrderId}
          showNextSteps={false}
          onBackToOrders={handleBackToOrders}
          onReorder={() => handleReorder(helpOrderId)}
          historyDetails={historyDetails}
          enableProfileModal={false}
        />
      </div>
    );
  }

  return (
    <>
    <CollapsibleSection
      title={t('title')}
      icon={FiShoppingBag}
      collapsible={false}
      showCollapseButton={false}
    >
      {loading ? (
        <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.xl }}>
          <Loader text={t('loading')} size="medium" />
        </div>
      ) : error ? (
        <div className="text-center" style={{ padding: fluidSizing.space.lg }}>
          <p className="text-red-500" style={{ fontSize: fluidSizing.text.sm }}>{error}</p>
          <button 
            className="text-primario hover:underline"
            style={{ marginTop: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
            onClick={() => window.location.reload()}
          >
            {t('retry')}
          </button>
        </div>
      ) : orders.length === 0 ? (
        <div className="text-center" style={{ padding: fluidSizing.space.lg }}>
          <p className="text-gray-500" style={{ fontSize: fluidSizing.text.sm }}>
            {t('empty')}
          </p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
          {paginatedOrders.map(order => {
            // Determinar si es una orden especial (membresía o paquete FC)
            const isMembershipOrder = order.orderType === 'membership_purchase';
            const isFCOrder = order.orderType === 'virtual_coins_purchase';
            const isSpecialOrder = isMembershipOrder || isFCOrder;
            
            // Título y badge según tipo de orden
            const getOrderTitle = () => {
              if (isMembershipOrder) return t('orderTitle.membership', { id: order.id });
              if (isFCOrder) return t('orderTitle.VirtualCoins', { id: order.id });
              return t('orderTitle.regular', { id: order.id });
            };
            
            const getOrderTypeBadge = () => {
              if (isMembershipOrder) {
                return (
                  <span className="inline-flex items-center rounded-full bg-purple-100 text-purple-700 whitespace-nowrap"
                    style={{ padding: `2px ${fluidSizing.space.xs}`, fontSize: fluidSizing.text.xs, gap: '2px' }}
                  >
                    <FiAward style={{ width: 10, height: 10 }} />
                    {t('orderTypes.membership')}
                  </span>
                );
              }
              if (isFCOrder) {
                return (
                  <span className="inline-flex items-center rounded-full bg-yellow-100 text-yellow-700 whitespace-nowrap"
                    style={{ padding: `2px ${fluidSizing.space.xs}`, fontSize: fluidSizing.text.xs, gap: '2px' }}
                  >
                    <FiDollarSign style={{ width: 10, height: 10 }} />
                    {t('orderTypes.fcBadgeLabel')}
                  </span>
                );
              }
              return null;
            };
            
            return (
            <CollapsibleSection
              key={order.id}
              variant="soft"
              title={getOrderTitle()}
              defaultExpanded={false}
              headerLayout="stacked"
              subtitle={
                <div className="flex flex-col">
                  {order.orderRating && (
                    <span className="text-primario" style={{ fontSize: fluidSizing.text.xs }}>
                      {'★'.repeat(order.orderRating)}{'☆'.repeat(5 - order.orderRating)}
                    </span>
                  )}
                  <VirtualCoinPrice amount={order.total} size="sm" showLabel={false} className="font-semibold whitespace-nowrap" />
                </div>
              }
              headerRight={
                <span className={`inline-flex items-center rounded-full whitespace-nowrap ${getStatusLabel(order.status).color}`}
                  style={{ padding: `2px ${fluidSizing.space.xs}`, fontSize: fluidSizing.text.xs }}
                >
                  {getStatusLabel(order.status).text}
                </span>
              }
              headerExtra={getOrderTypeBadge()}
            >
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                {/* Fecha */}
                <div className="flex justify-between items-center">
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    {formatDate(order.date)}
                  </span>
                </div>

                {/* Contenido específico según tipo de orden */}
                {isMembershipOrder ? (
                  // UI para compra de membresía
                  <div className="bg-purple-50 rounded-lg" style={{ padding: fluidSizing.space.md }}>
                    <div className="flex items-center" style={{ gap: fluidSizing.space.sm, marginBottom: fluidSizing.space.sm }}>
                      <div className="bg-purple-100 rounded-full flex items-center justify-center" style={{ width: 40, height: 40 }}>
                        <FiAward className="text-purple-600" style={{ width: 20, height: 20 }} />
                      </div>
                      <div>
                        <p className="text-oscuro font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                          {order.items[0]?.name || 'Membresía'}
                        </p>
                        <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                          {t('orderTypes.membershipPurchase')}
                        </p>
                      </div>
                    </div>
                    {(order.membershipDays || order.membershipPoints) && (
                      <div className="flex flex-wrap" style={{ gap: fluidSizing.space.sm }}>
                        {order.membershipDays && (
                          <span className="text-purple-700 bg-purple-100 rounded-full" style={{ padding: `2px ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs }}>
                            {t('details.days', { count: order.membershipDays })}
                          </span>
                        )}
                        {order.membershipPoints && order.membershipPoints > 0 && (
                          <span className="text-purple-700 bg-purple-100 rounded-full" style={{ padding: `2px ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs }}>
                            {t('details.fcBonus', { count: order.membershipPoints })}
                          </span>
                        )}
                      </div>
                    )}
                  </div>
                ) : isFCOrder ? (
                  // UI para compra de paquete FC
                  <div className="bg-yellow-50 rounded-lg" style={{ padding: fluidSizing.space.md }}>
                    <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                      <div className="bg-yellow-100 rounded-full flex items-center justify-center" style={{ width: 40, height: 40 }}>
                        <FiDollarSign className="text-yellow-600" style={{ width: 20, height: 20 }} />
                      </div>
                      <div>
                        <p className="text-oscuro font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                          {order.items[0]?.name || t('orderTypes.fcPackageFallbackName')}
                        </p>
                        {order.fcTotalCoins && (
                          <p className="text-yellow-700 font-semibold" style={{ fontSize: fluidSizing.text.sm }}>
                            +{order.fcTotalCoins.toLocaleString()} FC
                          </p>
                        )}
                      </div>
                    </div>
                  </div>
                ) : (
                  // UI para retiros normales (beneficios)
                  <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
                    <p className="text-texto font-medium" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('details.assignedBenefits')}
                    </p>
                    {order.items.map(item => (
                      <div 
                        key={item.id} 
                        className="flex items-center bg-gray-50 rounded-lg"
                        style={{ padding: fluidSizing.space.sm, gap: fluidSizing.space.sm }}
                      >
                        <div 
                          className="flex-shrink-0 bg-white rounded-md overflow-hidden"
                          style={{ width: fluidSizing.size.buttonLg, height: fluidSizing.size.buttonLg }}
                        >
                          <img 
                            src={item.image} 
                            alt={item.name}
                            className="w-full h-full object-cover"
                            onError={(e) => {
                              (e.target as HTMLImageElement).src = '/wp-content/themes/Starter/assets/img/no-image.svg';
                            }}
                          />
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-oscuro font-medium truncate" style={{ fontSize: fluidSizing.text.xs }}>
                            {item.name}
                          </p>
                          <div className="flex items-center text-texto" style={{ gap: fluidSizing.space.xs, fontSize: fluidSizing.text.xs }}>
                            <VirtualCoinPrice amount={item.price} size="xs" showLabel={false} />
                            <span>x {item.quantity}</span>
                          </div>
                        </div>
                        <VirtualCoinPrice 
                          amount={item.price * item.quantity} 
                          size="xs" 
                          showLabel={false}
                          className="text-oscuro font-medium flex-shrink-0"
                        />
                      </div>
                    ))}
                  </div>
                )}

                {/* Calificación y observación del pedido */}
                {order.orderRating && (
                  <div className="bg-primario/5 border border-primario/10 rounded-lg" style={{ padding: fluidSizing.space.sm }}>
                    <div className="flex items-center" style={{ gap: fluidSizing.space.xs, marginBottom: order.orderObservation ? '4px' : '0' }}>
                      <span className="text-texto font-medium" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('details.orderRating', { defaultValue: 'Calificación del pedido' })}
                      </span>
                      <span className="text-primario" style={{ fontSize: fluidSizing.text.sm }}>
                        {'★'.repeat(order.orderRating)}{'☆'.repeat(5 - order.orderRating)}
                      </span>
                    </div>
                    {order.orderObservation && (
                      <p className="text-texto/80 italic" style={{ fontSize: fluidSizing.text.xs }}>
                        "{order.orderObservation}"
                      </p>
                    )}
                  </div>
                )}

                {/* Total */}
                <div 
                  className="flex justify-between items-center border-t border-secundario/30"
                  style={{ paddingTop: fluidSizing.space.sm }}
                >
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    {isSpecialOrder ? t('details.totalPaid') : t('details.managementFee')}
                  </span>
                  <VirtualCoinPrice amount={order.total} size="sm" showLabel={false} className="text-primario font-semibold" />
                </div>

                {/* Botones - solo para retiros normales */}
                {!isSpecialOrder && (() => {
                  const canRate = order.status === 'completed' && !order.orderRating;
                  const canConfirm = order.status === 'processing';
                  const hasAction = canRate || canConfirm;
                  return (
                    <div 
                      className={hasAction ? 'grid grid-cols-3' : 'grid grid-cols-2'}
                      style={{ gap: fluidSizing.space.sm }}
                    >
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          handleRequestHelp(order.id);
                        }}
                        className="flex items-center justify-center border border-gray-300 rounded-lg text-texto bg-white hover:bg-gray-50 transition-colors"
                        style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
                      >
                        <FiEye style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                        <span>{t('buttons.viewDetails')}</span>
                      </button>
                      {canConfirm && (
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleConfirmOrder(order);
                          }}
                          disabled={confirmingOrder}
                          className="flex items-center justify-center rounded-lg text-primario border-2 border-primario bg-white hover:bg-primario/5 transition-colors disabled:opacity-50"
                          style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
                        >
                          <FiPackage style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                          <span>{confirmingOrder ? t('buttons.confirming', { defaultValue: 'Confirmando...' }) : t('buttons.confirmReceived', { defaultValue: 'Recibí mi pedido' })}</span>
                        </button>
                      )}
                      {canRate && (
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleRateOrder(order);
                          }}
                          className="flex items-center justify-center rounded-lg text-primario border-2 border-primario bg-white hover:bg-primario/5 transition-colors"
                          style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
                        >
                          <FiStar style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                          <span>{t('buttons.rate', { defaultValue: 'Calificar' })}</span>
                        </button>
                      )}
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          handleReorder(order.id);
                        }}
                        disabled={reorderingOrderId === order.id}
                        className="relative flex items-center justify-center rounded-lg text-white bg-primario hover:bg-hover transition-colors disabled:opacity-70 disabled:cursor-wait overflow-hidden"
                        style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
                      >
                        <FiRepeat style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                        <span>{t('buttons.repeat')}</span>
                        {reorderingOrderId === order.id && (
                          <div className="absolute inset-0 bg-primario/80 flex items-center justify-center">
                            <Loader size="xsmall" text="" />
                          </div>
                        )}
                      </button>
                    </div>
                  );
                })()}
              </div>
            </CollapsibleSection>
            );
          })}
          
          {/* Paginación */}
          {totalPages > 1 && (
            <Pagination
              currentPage={currentPage}
              totalPages={totalPages}
              onPageChange={setCurrentPage}
              className="mt-4"
            />
          )}
        </div>
      )}
    </CollapsibleSection>

    {orderForRating && (
      <OrderRatingModal
        isOpen={!!orderForRating}
        onClose={() => setOrderForRating(null)}
        order={orderForRating}
        reviewPoints={reviewPoints}
        submitting={ratingSubmitting}
        onSubmit={async (formData) => {
          const result = await rateOrder(formData);
          handleRatingSuccess(orderForRating.order_id, formData.rating);
          return result;
        }}
      />
    )}
    </>
  );
};

export default OrdersSection;

