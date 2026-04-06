import React from 'react';
import { useTranslation } from 'react-i18next';
import { Address } from '../../../../contexts/types/auth.types';
import { AddressDetails } from './types';
import PhoneInput from '../../../../components/auth/form-inputs/PhoneInput';
import { getDepartments, getMunicipalitiesByDepartment } from '../../../../data/colombiaLocations';

// Tipos de vía en Colombia
const tiposVia = [
  'Calle', 'Carrera', 'Avenida', 'Diagonal', 'Transversal', 'Circular', 'Autopista', 'Variante', 'Vía'
];

// Lista de departamentos de Colombia (desde el archivo de datos)
const colombianDepartments = getDepartments();



// Opciones de cardinales
const cardinales = ['Norte', 'Sur', 'Este', 'Oeste'];

interface AddressFormProps {
  formData: Omit<Address, 'id' | 'isDefault'>;
  direccionDetalle: AddressDetails;
  onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void;
  onDireccionChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void;
  onCheckboxChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onSubmit: (e: React.FormEvent) => void;
  onCancel: () => void;
}

/**
 * Componente para el formulario de direcciones
 */
const AddressForm: React.FC<AddressFormProps> = ({
  formData,
  direccionDetalle,
  onChange,
  onDireccionChange,
  onCheckboxChange,
  onSubmit,
  onCancel
}) => {
  const { t } = useTranslation('addressesSection');
  return (
    <div className="bg-gray-50 p-3 md:p-4 rounded-md mb-4 md:mb-6">
      <h2 className="text-sm md:text-md font-medium text-gray-700 mb-3 md:mb-4" id="address-form-title">
        {t('formTitle.new')}
      </h2>
      
      <form onSubmit={onSubmit} aria-labelledby="address-form-title">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
          {/* 1. Nombre de la dirección */}
          <div>
            <label htmlFor="address-name" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
              {t('form.nameLabel')}
            </label>
            <input
              id="address-name"
              type="text"
              name="name"
              value={formData.name}
              onChange={onChange}
              placeholder={t('form.namePlaceholder')}
              className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
              required
              aria-required="true"
            />
          </div>
          
          {/* 2. País */}
          <div>
            <label htmlFor="country" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
              {t('form.countryLabel')}
            </label>
            <select
              id="country"
              name="country"
              value={formData.country}
              onChange={onChange}
              className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
              required
              aria-required="true"
            >
              <option value="Colombia">Colombia</option>
              <option value="Ecuador">Ecuador</option>
              <option value="Perú">Perú</option>
              <option value="Venezuela">Venezuela</option>
            </select>
          </div>
          
          {/* 3. Teléfono */}
          <div>
            <label htmlFor="phone" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
              {t('form.phoneLabel')}
            </label>
            <PhoneInput 
              phone={formData.phone}
              setPhone={(value: string) => {
                const e = {
                  target: {
                    name: 'phone',
                    value
                  }
                } as React.ChangeEvent<HTMLInputElement>;
                onChange(e);
              }}
              disabled={false}
              showLabel={false}
              skipUniqueValidation={true}
            />
          </div>
          
          {/* 4. Código postal (opcional) */}
          <div>
            <label htmlFor="postal-code" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
              {t('form.postalCodeLabel')} <span className="text-gray-400 text-xs">{t('form.postalCodeOptional')}</span>
            </label>
            <input
              id="postal-code"
              type="text"
              name="postalCode"
              value={formData.postalCode}
              onChange={onChange}
              pattern="[0-9]*"
              inputMode="numeric"
              placeholder={t('form.postalCodePlaceholder')}
              className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
              aria-required="false"
            />
          </div>

          {/* 5. Dirección - Desglosada para Colombia */}
          {formData.country === 'Colombia' ? (
            <div className="md:col-span-2 border border-gray-200 rounded-md p-3">
              <fieldset>
                <legend className="block text-xs md:text-sm font-medium text-gray-700 mb-2">
                  {t('form.addressLabel')}
                </legend>
                
                <div className="grid grid-cols-1 md:grid-cols-3 gap-2 mb-2">
                  {/* Tipo de vía */}
                  <div>
                    <label htmlFor="tipo-via" className="block text-xs font-medium text-gray-600 mb-1">{t('form.roadType')}</label>
                    <select
                      id="tipo-via"
                      name="tipoVia"
                      value={direccionDetalle.tipoVia}
                      onChange={onDireccionChange}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      required
                      aria-required="true"
                    >
                      {tiposVia.map(tipo => (
                        <option key={tipo} value={tipo}>{tipo}</option>
                      ))}
                    </select>
                  </div>
                  
                  {/* Número de vía */}
                  <div>
                    <label htmlFor="numero-via" className="block text-xs font-medium text-gray-600 mb-1">{t('form.number')}</label>
                    <input
                      id="numero-via"
                      type="text"
                      name="numeroVia"
                      value={direccionDetalle.numeroVia}
                      onChange={onDireccionChange}
                      placeholder={t('form.placeholderRoadNumber')}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      required
                      aria-required="true"
                    />
                  </div>
                  
                  {/* Letra de vía */}
                  <div>
                    <label htmlFor="letra-via" className="block text-xs font-medium text-gray-600 mb-1">{t('form.letter')}</label>
                    <input
                      id="letra-via"
                      type="text"
                      name="letraVia"
                      value={direccionDetalle.letraVia}
                      onChange={onDireccionChange}
                      placeholder={t('form.placeholderRoadLetter')}
                      maxLength={1}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      aria-required="false"
                    />
                  </div>
                </div>
                
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2 mb-2">
                  {/* BIS */}
                  <div className="flex items-center">
                    <input
                      id="bis"
                      type="checkbox"
                      name="bis"
                      checked={direccionDetalle.bis}
                      onChange={onCheckboxChange}
                      className="h-4 w-4 text-primario focus:ring-primario border-gray-300 rounded"
                      aria-required="false"
                    />
                    <label htmlFor="bis" className="ml-2 block text-xs font-medium text-gray-600">BIS</label>
                  </div>
                  
                  {/* Cardinal 1 */}
                  <div>
                    <label htmlFor="cardinal1" className="block text-xs font-medium text-gray-600 mb-1">{t('form.cardinal')}</label>
                    <select
                      id="cardinal1"
                      name="cardinal1"
                      value={direccionDetalle.cardinal1}
                      onChange={onDireccionChange}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      aria-required="false"
                    >
                      <option value="">{t('form.none')}</option>
                      {cardinales.map(cardinal => (
                        <option key={cardinal} value={cardinal}>{cardinal}</option>
                      ))}
                    </select>
                  </div>
                  
                  {/* Número 1 */}
                  <div>
                    <label htmlFor="numero1" className="block text-xs font-medium text-gray-600 mb-1">{t('form.number1')}</label>
                    <input
                      id="numero1"
                      type="text"
                      name="numero1"
                      value={direccionDetalle.numero1}
                      onChange={onDireccionChange}
                      placeholder={t('form.placeholderNumber1')}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      required
                      aria-required="true"
                    />
                  </div>
                  
                  {/* Letra 1 */}
                  <div>
                    <label htmlFor="letra1" className="block text-xs font-medium text-gray-600 mb-1">{t('form.letter')}</label>
                    <input
                      id="letra1"
                      type="text"
                      name="letra1"
                      value={direccionDetalle.letra1}
                      onChange={onDireccionChange}
                      placeholder={t('form.placeholderLetter1')}
                      maxLength={1}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      aria-required="false"
                    />
                  </div>
                </div>
                
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mb-2">
                  {/* Número 2 */}
                  <div>
                    <label htmlFor="numero2" className="block text-xs font-medium text-gray-600 mb-1">{t('form.number2')}</label>
                    <input
                      id="numero2"
                      type="text"
                      name="numero2"
                      value={direccionDetalle.numero2}
                      onChange={onDireccionChange}
                      placeholder={t('form.placeholderNumber2')}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      required
                      aria-required="true"
                    />
                  </div>
                  
                  {/* Letra 2 */}
                  <div>
                    <label htmlFor="letra2" className="block text-xs font-medium text-gray-600 mb-1">{t('form.letter')}</label>
                    <input
                      id="letra2"
                      type="text"
                      name="letra2"
                      value={direccionDetalle.letra2}
                      onChange={onDireccionChange}
                      placeholder={t('form.placeholderLetter2')}
                      maxLength={1}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      aria-required="false"
                    />
                  </div>
                  
                  {/* Cardinal 2 */}
                  <div>
                    <label htmlFor="cardinal2" className="block text-xs font-medium text-gray-600 mb-1">{t('form.cardinal')}</label>
                    <select
                      id="cardinal2"
                      name="cardinal2"
                      value={direccionDetalle.cardinal2}
                      onChange={onDireccionChange}
                      className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                      aria-required="false"
                    >
                      <option value="">{t('form.none')}</option>
                      {cardinales.map(cardinal => (
                        <option key={cardinal} value={cardinal}>{cardinal}</option>
                      ))}
                    </select>
                  </div>
                </div>
                
                {/* Complemento */}
                <div>
                  <label htmlFor="complemento" className="block text-xs font-medium text-gray-600 mb-1">{t('form.complement')}</label>
                  <input
                    id="complemento"
                    type="text"
                    name="complemento"
                    value={direccionDetalle.complemento}
                    onChange={onDireccionChange}
                    placeholder={t('form.complementPlaceholder')}
                    className="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                    aria-required="false"
                  />
                </div>
                
                {/* Vista previa de la dirección */}
                <div className="mt-2 p-2 bg-gray-50 rounded-md">
                  <p className="text-xs text-gray-500">{t('form.preview')}</p>
                  <p className="text-sm font-medium">{formData.address}</p>
                </div>
              </fieldset>
            </div>
          ) : (
            <div className="md:col-span-2">
              <label htmlFor="address" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
                {t('form.addressLabel')}
              </label>
              <input
                id="address"
                type="text"
                name="address"
                value={formData.address}
                onChange={onChange}
                placeholder={t('form.addressPlaceholder')}
                className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                required
                aria-required="true"
              />
            </div>
          )}

          {/* 6. Departamento (primero para la cascada) */}
          <div>
            <label htmlFor="state" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
              {t('form.departmentLabel')}
            </label>
            {formData.country === 'Colombia' ? (
              <select
                id="state"
                name="state"
                value={formData.state}
                onChange={onChange}
                className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                required
                aria-required="true"
              >
                <option value="">{t('form.selectDepartment')}</option>
                {colombianDepartments.map(department => (
                  <option key={department} value={department}>{department}</option>
                ))}
              </select>
            ) : (
              <input
                id="state"
                type="text"
                name="state"
                value={formData.state}
                onChange={onChange}
                className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                required
                aria-required="true"
              />
            )}
          </div>

          {/* 7. Municipio/Ciudad (dinámico según departamento) */}
          <div>
            <label htmlFor="city" className="block text-xs md:text-sm font-medium text-gray-700 mb-1">
              {formData.country === 'Colombia' ? t('form.municipality') : t('form.city')}
            </label>
            {formData.country === 'Colombia' ? (
              <select
                id="city"
                name="city"
                value={formData.city}
                onChange={onChange}
                className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                required
                aria-required="true"
                disabled={!formData.state}
              >
                <option value="">{formData.state ? t('form.selectMunicipality') : t('form.selectDepartmentFirst')}</option>
                {formData.state && getMunicipalitiesByDepartment(formData.state).map(municipality => (
                  <option key={municipality} value={municipality}>{municipality}</option>
                ))}
              </select>
            ) : (
              <input
                id="city"
                type="text"
                name="city"
                value={formData.city}
                onChange={onChange}
                className="w-full px-2 py-1.5 md:px-3 md:py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primario focus:border-primario"
                required
                aria-required="true"
              />
            )}
          </div>
        </div>

        <div className="mt-4 md:mt-6 flex flex-col md:flex-row md:justify-end space-y-3 md:space-y-0">
          <button
            type="button"
            onClick={onCancel}
            className="md:mr-3 px-3 py-1.5 md:px-4 md:py-2 border border-gray-300 rounded-md text-xs md:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 flex items-center justify-center"
            style={{ backgroundColor: 'white', borderColor: '#e5e7eb' }}
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
            {t('form.cancel')}
          </button>
          <button
            type="submit"
            className="w-full bg-primario text-white px-4 py-2 rounded-md hover:bg-hover focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 transition-colors flex items-center justify-center"
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
            {t('form.save')}
          </button>
        </div>
      </form>
    </div>
  );
};

export default AddressForm;
