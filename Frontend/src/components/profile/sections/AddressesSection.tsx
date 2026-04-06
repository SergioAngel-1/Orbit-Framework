import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import alertService from '../../../services/alertService';
import { useAuth } from '../../../contexts/AuthContext';
import { Address } from '../../../contexts/types/auth.types';
import logger from '../../../utils/logger';
import { FiMapPin } from 'react-icons/fi';
import CollapsibleSection from '../../common/CollapsibleSection';
import { fluidSizing } from '../../../utils/fluidSizing';

// Componentes
import AddressForm from './address/AddressForm';
import AddressList from './address/AddressList';

// Tipos y utilidades
import { AddressDetails } from './address/types';
import {
  construirDireccionCompleta,
  parsearDireccion
} from './address/addressUtils';
import { getMunicipalitiesByDepartment } from '../../../data/colombiaLocations';

/**
 * Componente para gestionar las direcciones del usuario
 */
const AddressesSection = ({ initialShowAddForm = false }: { initialShowAddForm?: boolean }) => {
  const { user, saveAddress, deleteAddress, setDefaultAddress, getCurrentUser } = useAuth();
  const { t } = useTranslation('addressesSection');
  
  // Estados
  const [addresses, setAddresses] = useState<Address[]>([]);
  const [showAddForm, setShowAddForm] = useState(false);
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null);
  
  // Permitir abrir directamente el formulario de nueva dirección
  useEffect(() => {
    if (initialShowAddForm) {
      setShowAddForm(true);
    } else {
      setShowAddForm(false);
    }
  }, [initialShowAddForm]);

  // Estado para los detalles de dirección colombiana
  const [direccionDetalle, setDireccionDetalle] = useState<AddressDetails>({
    tipoVia: 'Calle',
    numeroVia: '',
    letraVia: '',
    bis: false,
    cardinal1: '',
    numero1: '',
    letra1: '',
    numero2: '',
    letra2: '',
    cardinal2: '',
    complemento: ''
  });
  
  // Estado para el formulario de dirección
  const [formData, setFormData] = useState<Omit<Address, 'id' | 'isDefault'>>({
    name: '',
    address: '',
    city: '',
    state: '',
    postalCode: '',
    country: 'Colombia',
    phone: ''
  });

  // Cargar direcciones del usuario
  useEffect(() => {
    if (user && user.addresses) {
      logger.info('AddressesSection', 'Actualizando direcciones desde el usuario:', user.addresses);
      setAddresses(user.addresses);
    }
  }, [user]);
  
  // Asegurar que tenemos los datos más recientes al montar el componente
  useEffect(() => {
    const loadAddresses = async () => {
      try {
        logger.info('AddressesSection', 'Cargando datos de usuario al montar el componente');
        await getCurrentUser();
      } catch (error) {
        logger.error('AddressesSection', 'Error al cargar datos del usuario', error);
      }
    };
    
    loadAddresses();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  
  // Precargar el teléfono del usuario cuando se muestra el formulario
  useEffect(() => {
    if (showAddForm && !editingAddressId && user?.phone && formData.phone === '') {
      // Solo precargar si estamos agregando una nueva dirección (no editando)
      // y si el usuario tiene un teléfono configurado y el campo está vacío
      setFormData(prev => ({
        ...prev,
        phone: user.phone || ''
      }));
      
      logger.info('AddressesSection', 'Precargando teléfono del usuario:', user.phone);
    }
  }, [showAddForm, editingAddressId, user?.phone, formData.phone]);

  // Efecto para limpiar el municipio cuando cambia el departamento
  useEffect(() => {
    if (formData.country === 'Colombia' && formData.state) {
      // Obtener municipios del departamento seleccionado
      const municipalities = getMunicipalitiesByDepartment(formData.state);
      
      // Si el municipio actual no está en la lista del nuevo departamento, limpiarlo
      if (formData.city && !municipalities.includes(formData.city)) {
        setFormData(prev => ({
          ...prev,
          city: ''
        }));
      }
    }
  }, [formData.state, formData.country, formData.city]);
  
  // Efecto para actualizar el campo de dirección completa cuando cambian los componentes
  useEffect(() => {
    if (formData.country === 'Colombia') {
      // Construir la dirección completa a partir de los componentes
      const direccionCompleta = construirDireccionCompleta(direccionDetalle);
      setFormData(prev => ({
        ...prev,
        address: direccionCompleta
      }));
    }
  }, [direccionDetalle, formData.country]);

  /**
   * Resetea el formulario a sus valores iniciales
   */
  const resetForm = useCallback(() => {
    setFormData({
      name: '',
      address: '',
      city: '',
      state: '',
      postalCode: '',
      country: 'Colombia',
      phone: ''
    });
    setDireccionDetalle({
      tipoVia: 'Calle',
      numeroVia: '',
      letraVia: '',
      bis: false,
      cardinal1: '',
      numero1: '',
      letra1: '',
      numero2: '',
      letra2: '',
      cardinal2: '',
      complemento: ''
    });
    setEditingAddressId(null);
    setShowAddForm(false);
  }, []);

  /**
   * Maneja cambios en los campos del formulario principal
   */
  const handleChange = useCallback((e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    
    // Validación para campos que solo aceptan números (solo para código postal)
    if (name === 'postalCode' && !/^\d*$/.test(value)) {
      return; // No actualizar si contiene caracteres no numéricos
    }
    
    // Para el teléfono, permitimos el formato con prefijo internacional (+XX)
    // El componente PhoneInput se encargará de la validación
    
    // Actualizar el valor en el formulario
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
  }, []);
  
  /**
   * Maneja cambios en los componentes de dirección colombiana
   */
  const handleDireccionChange = useCallback((e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    
    // Validación para campos numéricos
    if (['numeroVia', 'numero1', 'numero2'].includes(name)) {
      // Solo permitir números
      if (!/^\d*$/.test(value)) {
        return; // No actualizar si contiene caracteres no numéricos
      }
    }
    
    // Validación para campos de letras
    if (['letraVia', 'letra1', 'letra2'].includes(name)) {
      // Solo permitir letras
      if (!/^[a-zA-Z]*$/.test(value)) {
        return; // No actualizar si contiene caracteres no alfabéticos
      }
    }
    
    setDireccionDetalle(prev => ({
      ...prev,
      [name]: value
    }));
  }, []);
  
  /**
   * Maneja cambios en los checkboxes
   */
  const handleCheckboxChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, checked } = e.target;
    setDireccionDetalle(prev => ({
      ...prev,
      [name]: checked
    }));
  }, []);

  /**
   * Maneja el envío del formulario
   */
  const handleSubmit = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    
    try {
      // Verificar límite de direcciones
      if (!editingAddressId && addresses.length >= 3) {
        alertService.error(t('messages.maxReached'));
        return;
      }
      
      const addressData: Partial<Address> = {
        ...formData
      };
      
      // Si estamos editando, incluir el ID
      if (editingAddressId !== null) {
        addressData.id = editingAddressId;
      }
      
      // Guardar la dirección
      await saveAddress(addressData);
      
      // Si estamos editando, actualizamos la dirección en la lista local
      if (editingAddressId !== null) {
        setAddresses(prevAddresses => prevAddresses.map(addr => 
          addr.id === editingAddressId ? { ...addr, ...formData, id: editingAddressId } : addr
        ));
        alertService.success(t('messages.updated'));
      } else {
        // Si es una dirección nueva, la agregamos a la lista local
        // Nota: No podemos agregar el ID porque lo genera el backend
        // Actualizaremos la lista completa al recargar el componente
        alertService.success(t('messages.added'));
      }
      
      resetForm();
    } catch (error: any) {
      alertService.error(error.message || t('messages.saveError'));
    }
  }, [addresses.length, editingAddressId, formData, resetForm, saveAddress]);

  /**
   * Maneja la edición de una dirección existente
   */
  const handleEditAddress = useCallback((address: Address) => {
    // Establecer los datos del formulario con la dirección seleccionada
    setFormData({
      name: address.name,
      address: address.address,
      city: address.city,
      state: address.state,
      postalCode: address.postalCode,
      country: address.country,
      phone: address.phone
    });
    
    // Si es una dirección colombiana, parsear los componentes
    if (address.country === 'Colombia') {
      setDireccionDetalle(parsearDireccion(address.address));
    }
    
    // Establecer el ID de la dirección que estamos editando
    setEditingAddressId(address.id);
    // Mostrar el formulario
    setShowAddForm(true);
    
    logger.info('AddressesSection', 'Editando dirección:', address);
  }, []);
  
  /**
   * Maneja la eliminación de una dirección
   */
  const handleDeleteAddress = useCallback(async (id: number) => {
    try {
      // Eliminar la dirección - no esperamos un valor de retorno
      await deleteAddress(id);
      
      // Actualizar localmente la lista de direcciones eliminando la dirección
      setAddresses(prevAddresses => prevAddresses.filter(addr => addr.id !== id));
      
      alertService.success(t('messages.deleted'));
      logger.info('AddressesSection', 'Dirección eliminada:', id);
    } catch (error: any) {
      alertService.error(error.message || t('messages.deleteError'));
      logger.error('AddressesSection', 'Error al eliminar dirección', error);
    }
  }, [deleteAddress]);
  
  /**
   * Maneja el establecimiento de una dirección como predeterminada
   */
  const handleSetDefaultAddress = useCallback(async (id: number) => {
    try {
      // Cuando se cambia la dirección predeterminada, sí necesitamos recargar los datos completos
      // ya que esto afecta a otros componentes de la aplicación
      await setDefaultAddress(id);
      
      // Recargar los datos del usuario para actualizar todo el contexto
      await getCurrentUser();
      
      alertService.success(t('messages.defaultUpdated'));
      logger.info('AddressesSection', 'Dirección predeterminada actualizada:', id);
    } catch (error: any) {
      alertService.error(error.message || t('messages.defaultError'));
      logger.error('AddressesSection', 'Error al establecer dirección predeterminada', error);
    }
  }, [setDefaultAddress, getCurrentUser]);

  /**
   * Maneja la acción de añadir una nueva dirección
   */
  const handleAddNewAddress = useCallback(() => {
    resetForm();
    setShowAddForm(true);
  }, [resetForm]);

  return (
    <CollapsibleSection
      title={t('title')}
      icon={FiMapPin}
      collapsible={false}
      showCollapseButton={false}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
        {!showAddForm ? (
          <>
            <AddressList 
              addresses={addresses}
              onEdit={handleEditAddress}
              onDelete={handleDeleteAddress}
              onSetDefault={handleSetDefaultAddress}
              onAddNew={handleAddNewAddress}
            />
            
            {addresses.length < 3 && (
              <button
                onClick={handleAddNewAddress}
                className="w-full flex items-center justify-center bg-primario text-white rounded-lg hover:bg-hover transition-all duration-300 font-medium"
                style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
                aria-label={t('addButtonAria')}
              >
                <FiMapPin style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm, marginRight: fluidSizing.space.xs }} />
                {t('addButton')}
              </button>
            )}
          </>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
            <div 
              className="bg-primario/10 border border-primario/20 rounded-lg"
              style={{ padding: fluidSizing.space.sm }}
            >
              <p className="text-primario font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                {editingAddressId !== null ? t('formTitle.edit') : t('formTitle.new')}
              </p>
            </div>
            
            <AddressForm
              formData={formData}
              direccionDetalle={direccionDetalle}
              onChange={handleChange}
              onDireccionChange={handleDireccionChange}
              onCheckboxChange={handleCheckboxChange}
              onSubmit={handleSubmit}
              onCancel={resetForm}
            />
          </div>
        )}
      </div>
    </CollapsibleSection>
  );
};

export default AddressesSection;
