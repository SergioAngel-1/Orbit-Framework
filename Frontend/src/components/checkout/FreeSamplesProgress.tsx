import React from 'react';
import { useTranslation } from 'react-i18next';
import MembershipBadge from '../common/MembershipBadge';
import { useMembership } from '../../contexts/MembershipContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';
import type { FreeSamplesData } from '../../services/membership/membershipTypes';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface FreeSamplesProgressProps {
  freeSamples: FreeSamplesData | null;
  isAuthenticated: boolean;
}

/**
 * Componente que muestra el progreso de muestras gratis del usuario
 * 
 * LÓGICA v2.0:
 * - Usa orders_in_current_cycle para mostrar progreso en el ciclo actual
 * - Usa orders_until_next directamente del backend (ya calculado correctamente)
 * - No hace cálculos complejos en frontend, solo renderiza datos del backend
 */
const FreeSamplesProgress: React.FC<FreeSamplesProgressProps> = ({
  freeSamples,
  isAuthenticated,
}) => {
  const { currentLevel } = useMembership();
  const { t } = useTranslation('freeSamplesProgress');
  const { currentLang } = useLanguage();

  if (!isAuthenticated || !freeSamples) {
    return null;
  }

  // Extraer datos del backend (v2.0)
  const {
    // Configuración
    total_grams,
    grams_per_delivery,
    every_orders,
    // Estado actual
    orders_in_current_cycle,
    orders_until_next,
    deliveries_earned,
    grams_delivered,
    grams_remaining,
    can_receive_more,
    // Última entrega
    last_delivery_at,
    last_delivery_grams,
    just_delivered,
    // Pedidos pendientes
    pending_orders_count,
  } = freeSamples;

  // Formatear fecha
  const formatDate = (value?: string | null): string | null => {
    if (!value) return null;
    const normalized = value.replace(' ', 'T');
    const parsed = new Date(normalized);
    if (Number.isNaN(parsed.getTime())) return null;
    const locale = currentLang === 'en' ? 'en-US' : 'es-ES';
    return parsed.toLocaleDateString(locale, {
      day: 'numeric',
      month: 'short',
    });
  };

  // Valores para la UI
  // LÓGICA v3.0: El backend ahora calcula orders_in_current_cycle correctamente (0 a every_orders)
  // El ciclo real es de every_orders + 1 pedidos, donde el último gana muestra
  const totalSegments = every_orders > 0 ? every_orders : 1;
  const completedSegments = Math.min(totalSegments, Math.max(0, orders_in_current_cycle));
  
  // Pedidos pendientes (en processing) - se muestran visualmente con patrón diferente
  // Limitar a los espacios disponibles en la barra (máximo totalSegments - completedSegments)
  // Si hay más pedidos pendientes que espacios, significa que hay un ciclo completo pendiente
  const pendingCount = pending_orders_count ?? 0;
  const availableSlots = totalSegments - completedSegments;
  const pendingSegments = Math.min(availableSlots, pendingCount);
  
  // Calcular si hay un pedido "invisible" pendiente (el que gana muestra)
  // Esto ocurre cuando completedSegments + pendingCount > totalSegments
  const progressWithPending = completedSegments + pendingCount;
  const hasPendingInvisible = progressWithPending > totalSegments;
  
  const lastDeliveryLabel = formatDate(last_delivery_at);
  const lastDeliveryGramsDisplay = last_delivery_grams ?? grams_per_delivery;
  
  // Calcular máximo de entregas posibles
  const maxDeliveries = grams_per_delivery > 0 ? Math.floor(total_grams / grams_per_delivery) : 0;

  // Estado: Beneficio completado (ya recibió todas las muestras)
  if (!can_receive_more) {
    return (
      <div 
        className="bg-primario/10 border border-primario/20 rounded-lg"
        style={{ padding: fluidSizing.space.sm }}
      >
        <div 
          className="flex items-center"
          style={{ gap: fluidSizing.space.xs }}
        >
          <MembershipBadge level={currentLevel} size="xs" disableModal />
          <span className="font-semibold text-primario" style={{ fontSize: fluidSizing.text.xs }}>
            {t('completed.title')}
          </span>
        </div>
        <p className="text-primario/70" style={{ fontSize: fluidSizing.text['2xs'], marginTop: fluidSizing.space.xs }}>
          {t('completed.summary', { delivered: grams_delivered, total: total_grams, count: deliveries_earned })}
        </p>
      </div>
    );
  }

  // Estado: Acaba de recibir muestra (celebración) - solo si el ciclo está en 0
  if (just_delivered && orders_in_current_cycle === 0) {
    return (
      <div 
        className="bg-primario/10 border border-primario/20 rounded-lg"
        style={{ padding: fluidSizing.space.sm }}
      >
        <div 
          className="flex items-center"
          style={{ gap: fluidSizing.space.xs, marginBottom: fluidSizing.space.sm }}
        >
          <MembershipBadge level={currentLevel} size="xs" disableModal />
          <span className="font-semibold text-primario" style={{ fontSize: fluidSizing.text.xs }}>
            {t('justDelivered.title')}
          </span>
        </div>
        <p 
          className="font-medium text-primario/90"
          style={{ fontSize: fluidSizing.text['2xs'], marginBottom: fluidSizing.space.sm }}
        >
          {t('justDelivered.received', { grams: lastDeliveryGramsDisplay })}
        </p>
        
        {/* Mostrar progreso del nuevo ciclo */}
        <div 
          className="bg-white/50 rounded"
          style={{ padding: fluidSizing.space.sm }}
        >
          <div 
            className="flex items-center justify-between"
            style={{ marginBottom: fluidSizing.space.xs }}
          >
            <span className="text-primario/70" style={{ fontSize: fluidSizing.text['2xs'] }}>
              {t('justDelivered.newCycle')}
            </span>
            <span className="font-medium text-primario" style={{ fontSize: fluidSizing.text['2xs'] }}>
              {pendingSegments > 0 ? `${completedSegments}+${pendingSegments}` : completedSegments}/{totalSegments} {t('progress.contributions')}
            </span>
          </div>
          <div className="flex" style={{ gap: fluidSizing.space.xs }}>
            {Array.from({ length: totalSegments }).map((_, index) => {
              const isCompleted = index < completedSegments;
              const isPending = index >= completedSegments && index < completedSegments + pendingSegments;
              
              let backgroundColor = '#E5E7EB';
              let borderColor = '#D1D5DB';
              
              if (isCompleted) {
                backgroundColor = '#C72C6C';
                borderColor = '#C72C6C';
              } else if (isPending) {
                backgroundColor = 'repeating-linear-gradient(45deg, #C72C6C, #C72C6C 4px, #ec4899 4px, #ec4899 8px)';
                borderColor = '#C72C6C';
              }
              
              return (
                <div
                  key={index}
                  className="flex-1 rounded-full"
                  style={{
                    height: '8px',
                    background: backgroundColor,
                    border: `1px solid ${borderColor}`,
                  }}
                />
              );
            })}
          </div>
          {/* Mensaje según estado de pedidos pendientes */}
          {hasPendingInvisible ? (
            <p className="text-pink-600 font-medium" style={{ fontSize: fluidSizing.text['2xs'], marginTop: fluidSizing.space.xs }}>
              {t('messages.pendingWillEarn', { grams: grams_per_delivery })}
            </p>
          ) : pendingSegments >= totalSegments ? (
            <p className="text-pink-600 font-medium" style={{ fontSize: fluidSizing.text['2xs'], marginTop: fluidSizing.space.xs }}>
              {t('messages.pendingInProcess', { count: pendingSegments, grams: grams_per_delivery })}
            </p>
          ) : pendingSegments > 0 ? (
            <p className="text-pink-600 font-medium" style={{ fontSize: fluidSizing.text['2xs'], marginTop: fluidSizing.space.xs }}>
              {t('messages.pendingSimple', { count: pendingSegments })}
            </p>
          ) : null}
        </div>
        
        <p className="text-primario/60" style={{ fontSize: fluidSizing.text['2xs'], marginTop: fluidSizing.space.sm }}>
          {t('summary.deliveries', { earned: deliveries_earned, max: maxDeliveries })} • {t('summary.remaining', { grams: grams_remaining })}
        </p>
      </div>
    );
  }

  // Estado normal: En progreso
  // Considerar pedidos pendientes: si completados + pendientes = total, el próximo pedido completará el ciclo
  const totalProgress = completedSegments + pendingSegments;
  const willEarnWithNextOrder = totalProgress === totalSegments - 1 || orders_until_next === 1;

  return (
    <div 
      className="bg-primario/5 border border-primario/15 rounded-lg"
      style={{ padding: fluidSizing.space.xs }}
    >
      {/* Header */}
      <div 
        className="flex items-center justify-between"
        style={{ marginBottom: fluidSizing.space.xs }}
      >
        <div className="flex items-center" style={{ gap: '4px' }}>
          <MembershipBadge level={currentLevel} size="xs" disableModal />
          <span className="font-semibold text-primario" style={{ fontSize: fluidSizing.text['2xs'] }}>
            {t('progress.title')}
          </span>
        </div>
        <span className="font-medium text-primario" style={{ fontSize: fluidSizing.text['2xs'] }}>
          {completedSegments}{pendingSegments > 0 ? `+${pendingSegments}` : ''}/{totalSegments} {t('progress.contributions')}
        </span>
      </div>

      {/* Barra de progreso */}
      <div 
        className="flex"
        style={{ gap: '3px', marginBottom: fluidSizing.space.xs }}
      >
        {Array.from({ length: totalSegments }).map((_, index) => {
          const isCompleted = index < completedSegments;
          const isPending = index >= completedSegments && index < completedSegments + pendingSegments;
          const isNext = willEarnWithNextOrder && index === completedSegments + pendingSegments;
          
          let background = '#E5E7EB';
          let borderColor = '#D1D5DB';
          
          if (isCompleted) {
            // Pedidos completados: fucsia sólido
            background = '#C72C6C';
            borderColor = '#C72C6C';
          } else if (isPending) {
            // Pedidos en processing: fucsia con rayas (patrón visual de "pendiente")
            background = 'repeating-linear-gradient(45deg, #C72C6C, #C72C6C 2px, #f9a8d4 2px, #f9a8d4 4px)';
            borderColor = '#C72C6C';
          } else if (isNext) {
            // Próximo segmento a completar: rosa claro
            background = '#fce7f3';
            borderColor = '#ec4899';
          }

          return (
            <div
              key={index}
              className="flex-1 rounded-full transition-colors duration-300"
              style={{ 
                height: '8px',
                background: background, 
                border: `1px solid ${borderColor}` 
              }}
            />
          );
        })}
      </div>

      {/* Mensaje de estado */}
      {hasPendingInvisible ? (
        <p className="font-medium text-pink-600" style={{ fontSize: fluidSizing.text['2xs'] }}>
          {t('messages.pendingWillEarnExcited', { grams: grams_per_delivery })}
        </p>
      ) : pendingSegments > 0 && totalProgress >= totalSegments ? (
        <p className="font-medium text-pink-600" style={{ fontSize: fluidSizing.text['2xs'] }}>
          {t('messages.pendingCompleteCycle', { grams: grams_per_delivery })}
        </p>
      ) : completedSegments >= totalSegments ? (
        <p className="font-medium text-pink-600" style={{ fontSize: fluidSizing.text['2xs'] }}>
          {t('messages.cycleComplete', { grams: grams_per_delivery })}
        </p>
      ) : (
        <p className="text-primario/70" style={{ fontSize: fluidSizing.text['2xs'] }} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('messages.completeMore', { count: totalSegments - completedSegments - pendingSegments, grams: grams_per_delivery })) }} />
      )}

      {/* Resumen compacto */}
      <p className="text-primario/60" style={{ fontSize: fluidSizing.text['2xs'], lineHeight: 1.3, marginTop: '8px' }}>
        {t('summary.deliveries', { earned: deliveries_earned, max: maxDeliveries })} • {t('summary.delivered', { grams: grams_delivered })} • {t('summary.remaining', { grams: grams_remaining })}
        {lastDeliveryLabel && (
          <span className="text-primario/50">
            {' '}• {t('summary.lastSample', { grams: lastDeliveryGramsDisplay, date: lastDeliveryLabel })}
          </span>
        )}
      </p>
    </div>
  );
};

export default FreeSamplesProgress;
